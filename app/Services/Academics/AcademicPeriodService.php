<?php

namespace App\Services\Academics;

use App\Enums\AcademicScheme;
use App\Enums\PeriodState;
use App\Exceptions\AcademicDomainException;
use App\Models\AcademicCohort;
use App\Models\AcademicPeriod;
use App\Models\AcademicProgram;
use App\Models\User;
use App\Repositories\Academics\AcademicPeriodRepository;
use App\Services\AuditTrail\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class AcademicPeriodService
{
    public function __construct(
        protected AcademicPeriodRepository $periodRepository,
        protected AuditLogger $auditLogger
    ) {}

    public function create(
        array $data,
        ?User $actor = null,
        ?string $ip = null,
        ?string $requestId = null
    ): AcademicPeriod {
        return DB::transaction(function () use ($data, $actor, $ip, $requestId) {
            $program = AcademicProgram::where('id', (int) $data['program_id'])->lockForUpdate()->first();
            if (! $program) {
                abort(404, 'Academic program not found.');
            }

            $startDate = $data['start_date'];
            $endDate = $data['end_date'];

            if ($startDate > $endDate) {
                throw new AcademicDomainException(
                    'VALIDATION_ERROR',
                    'Period start_date must be on or before end_date.',
                    422,
                    ['end_date' => ['End date must be on or after start date.']]
                );
            }

            if ($program->scheme === AcademicScheme::CALENDAR_YEAR) {
                if (! empty($data['cohort_id']) || ! empty($data['study_year_number'])) {
                    throw new AcademicDomainException(
                        'INVALID_COHORT_SCHEME',
                        'Calendar-year periods cannot specify a cohort_id or study_year_number.',
                        422
                    );
                }

                if (empty($data['calendar_year'])) {
                    throw new AcademicDomainException(
                        'VALIDATION_ERROR',
                        'Calendar-year period requires calendar_year.',
                        422,
                        ['calendar_year' => ['Calendar year is required for calendar-scheme programs.']]
                    );
                }

                $calendarYear = (int) $data['calendar_year'];
                if ($this->periodRepository->findCalendarPeriod($program->id, $calendarYear)) {
                    throw new AcademicDomainException(
                        'PERIOD_DATE_CONFLICT',
                        "Calendar year {$calendarYear} period already exists for this program.",
                        409
                    );
                }

                if ($this->periodRepository->hasOverlappingCalendarPeriod($program->id, $startDate, $endDate)) {
                    throw new AcademicDomainException(
                        'PERIOD_DATE_CONFLICT',
                        'Calendar-year period dates overlap with another period in the same program.',
                        409
                    );
                }

                $attributes = [
                    'program_id' => $program->id,
                    'cohort_id' => null,
                    'label' => trim($data['label']),
                    'calendar_year' => $calendarYear,
                    'study_year_number' => null,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'state' => PeriodState::DRAFT,
                    'lock_version' => 1,
                ];
            } else {
                if (! empty($data['calendar_year'])) {
                    throw new AcademicDomainException(
                        'VALIDATION_ERROR',
                        'Session-based periods must not specify calendar_year.',
                        422,
                        ['calendar_year' => ['Session-based periods use cohort_id and study_year_number instead of calendar_year.']]
                    );
                }

                if (empty($data['cohort_id']) || empty($data['study_year_number'])) {
                    throw new AcademicDomainException(
                        'VALIDATION_ERROR',
                        'Session-based period requires both cohort_id and study_year_number.',
                        422,
                        [
                            'cohort_id' => ['Cohort is required for session-based periods.'],
                            'study_year_number' => ['Study year number is required for session-based periods.'],
                        ]
                    );
                }

                $cohort = AcademicCohort::where('id', (int) $data['cohort_id'])->lockForUpdate()->first();
                if (! $cohort || $cohort->program_id !== $program->id) {
                    throw new AcademicDomainException(
                        'PROGRAM_RELATION_MISMATCH',
                        'Selected cohort does not belong to the specified academic program.',
                        422,
                        ['cohort_id' => ['Cohort belongs to a different academic program.']]
                    );
                }

                $studyYearNumber = (int) $data['study_year_number'];
                if ($studyYearNumber < 1 || $studyYearNumber > $program->duration_years) {
                    throw new AcademicDomainException(
                        'VALIDATION_ERROR',
                        "Study year number must be between 1 and {$program->duration_years}.",
                        422,
                        ['study_year_number' => ["Study year number must be between 1 and {$program->duration_years}."]]
                    );
                }

                if ($this->periodRepository->findSessionPeriod($cohort->id, $studyYearNumber)) {
                    throw new AcademicDomainException(
                        'DUPLICATE_ACADEMIC_CODE',
                        "Study year {$studyYearNumber} already exists for cohort '{$cohort->label}'.",
                        409
                    );
                }

                $this->validateCohortStageDates($cohort->id, $studyYearNumber, $startDate, $endDate);

                $attributes = [
                    'program_id' => $program->id,
                    'cohort_id' => $cohort->id,
                    'label' => trim($data['label']),
                    'calendar_year' => null,
                    'study_year_number' => $studyYearNumber,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'state' => PeriodState::DRAFT,
                    'lock_version' => 1,
                ];
            }

            try {
                $period = $this->periodRepository->create($attributes);
            } catch (QueryException $e) {
                throw new AcademicDomainException(
                    'DUPLICATE_ACADEMIC_CODE',
                    'Duplicate academic period detected under concurrent submission.',
                    409
                );
            }

            $this->auditLogger->record(
                'PERIOD_CREATED',
                $actor?->id,
                null,
                null,
                $period->only(['id', 'program_id', 'cohort_id', 'label', 'calendar_year', 'study_year_number', 'start_date', 'end_date', 'state', 'lock_version']),
                $ip,
                $requestId,
                null,
                'AcademicPeriod',
                $period->id
            );

            return $period;
        });
    }

    public function update(
        AcademicPeriod $period,
        array $data,
        ?User $actor = null,
        ?string $ip = null,
        ?string $requestId = null
    ): AcademicPeriod {
        if ($period->state === PeriodState::CLOSED) {
            throw new AcademicDomainException(
                'CLOSED_PERIOD',
                'Closed academic period is read-only and cannot be modified.',
                409
            );
        }

        if (isset($data['program_id']) && (int) $data['program_id'] !== $period->program_id) {
            throw new AcademicDomainException(
                'PROGRAM_RELATION_MISMATCH',
                'Cannot change the program of an existing academic period.',
                422
            );
        }

        $startDate = $data['start_date'] ?? $period->start_date->format('Y-m-d');
        $endDate = $data['end_date'] ?? $period->end_date->format('Y-m-d');

        if ($startDate > $endDate) {
            throw new AcademicDomainException(
                'VALIDATION_ERROR',
                'Period start_date must be on or before end_date.',
                422,
                ['end_date' => ['End date must be on or after start date.']]
            );
        }

        if ($period->cohort_id !== null) {
            $this->validateCohortStageDates($period->cohort_id, $period->study_year_number, $startDate, $endDate, $period->id);
        } else {
            if ($this->periodRepository->hasOverlappingCalendarPeriod($period->program_id, $startDate, $endDate, $period->id)) {
                throw new AcademicDomainException(
                    'PERIOD_DATE_CONFLICT',
                    'Calendar-year period dates overlap with another period in the same program.',
                    409
                );
            }
        }

        $before = $period->only(['label', 'start_date', 'end_date']);
        $updated = $this->periodRepository->update(
            $period,
            collect($data)->only(['label', 'start_date', 'end_date'])->toArray()
        );

        $this->auditLogger->record(
            'PERIOD_UPDATED',
            $actor?->id,
            null,
            $before,
            $updated->only(['label', 'start_date', 'end_date']),
            $ip,
            $requestId,
            null,
            'AcademicPeriod',
            $updated->id
        );

        return $updated;
    }

    protected function validateCohortStageDates(
        int $cohortId,
        int $studyYearNumber,
        string $startDate,
        string $endDate,
        ?int $excludePeriodId = null
    ): void {
        $existingPeriods = $this->periodRepository->getPeriodsForCohort($cohortId, $excludePeriodId);

        foreach ($existingPeriods as $existing) {
            $existingStart = $existing->start_date->format('Y-m-d');
            $existingEnd = $existing->end_date->format('Y-m-d');

            if ($startDate <= $existingEnd && $endDate >= $existingStart) {
                throw new AcademicDomainException(
                    'PERIOD_DATE_CONFLICT',
                    "Study period dates overlap with stage {$existing->study_year_number} ({$existingStart} to {$existingEnd}) in the same cohort.",
                    409
                );
            }

            if ($studyYearNumber > $existing->study_year_number && $startDate <= $existingEnd) {
                throw new AcademicDomainException(
                    'PERIOD_DATE_CONFLICT',
                    "Stage {$studyYearNumber} must start after stage {$existing->study_year_number} ends ({$existingEnd}).",
                    409
                );
            }

            if ($studyYearNumber < $existing->study_year_number && $endDate >= $existingStart) {
                throw new AcademicDomainException(
                    'PERIOD_DATE_CONFLICT',
                    "Stage {$studyYearNumber} must end before stage {$existing->study_year_number} starts ({$existingStart}).",
                    409
                );
            }
        }
    }
}
