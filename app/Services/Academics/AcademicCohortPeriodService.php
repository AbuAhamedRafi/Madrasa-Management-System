<?php

namespace App\Services\Academics;

use App\Enums\AcademicScheme;
use App\Enums\CohortStatus;
use App\Enums\PeriodState;
use App\Exceptions\AcademicDomainException;
use App\Models\AcademicCohort;
use App\Models\AcademicPeriod;
use App\Models\AcademicProgram;
use App\Models\User;
use App\Repositories\Academics\AcademicCohortRepository;
use App\Repositories\Academics\AcademicPeriodRepository;
use App\Repositories\Academics\AcademicProgramRepository;
use App\Services\AuditTrail\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class AcademicCohortPeriodService
{
    public function __construct(
        protected AcademicProgramRepository $programRepository,
        protected AcademicCohortRepository $cohortRepository,
        protected AcademicPeriodRepository $periodRepository,
        protected AuditLogger $auditLogger
    ) {}

    public function createCohort(
        array $data,
        ?User $actor = null,
        ?string $ip = null,
        ?string $requestId = null
    ): AcademicCohort {
        $program = $this->programRepository->findById((int) $data['program_id']);
        if (! $program) {
            abort(404, 'Academic program not found.');
        }

        if ($program->scheme !== AcademicScheme::SESSION_BASED) {
            throw new AcademicDomainException(
                'INVALID_COHORT_SCHEME',
                'Admission cohorts can only be created for SESSION_BASED programs.',
                422,
                ['program_id' => ['Calendar-year programs do not use admission cohorts.']]
            );
        }

        $label = trim($data['label']);
        if ($this->cohortRepository->findByProgramAndLabel($program->id, $label)) {
            throw new AcademicDomainException(
                'DUPLICATE_ACADEMIC_CODE',
                "Cohort label '{$label}' already exists for this program.",
                409,
                ['label' => ["Cohort label '{$label}' already exists for this program."]]
            );
        }

        $cohort = $this->cohortRepository->create([
            'program_id' => $program->id,
            'label' => $label,
            'intake_year' => (int) $data['intake_year'],
            'description' => $data['description'] ?? null,
            // Explicitly never infer dates from cohort label
            'start_date' => $data['start_date'] ?? null,
            'end_date' => $data['end_date'] ?? null,
            'status' => isset($data['status']) ? CohortStatus::from(strtoupper(trim($data['status']))) : CohortStatus::DRAFT,
        ]);

        $this->auditLogger->record(
            'COHORT_CREATED',
            $actor?->id,
            null,
            null,
            $cohort->only(['id', 'program_id', 'label', 'intake_year', 'status']),
            $ip,
            $requestId,
            null,
            'AcademicCohort',
            $cohort->id
        );

        return $cohort;
    }

    public function updateCohort(
        AcademicCohort $cohort,
        array $data,
        ?User $actor = null,
        ?string $ip = null,
        ?string $requestId = null
    ): AcademicCohort {
        if ($cohort->status === CohortStatus::CLOSED) {
            throw new AcademicDomainException(
                'CLOSED_PERIOD',
                'Closed cohort is read-only and cannot be edited without reopening.',
                409
            );
        }

        if (isset($data['program_id']) && (int) $data['program_id'] !== $cohort->program_id) {
            throw new AcademicDomainException(
                'PROGRAM_RELATION_MISMATCH',
                'Cohort cannot be transferred to a different program.',
                422
            );
        }

        if (isset($data['label'])) {
            $label = trim($data['label']);
            if ($this->cohortRepository->findByProgramAndLabel($cohort->program_id, $label, $cohort->id)) {
                throw new AcademicDomainException(
                    'DUPLICATE_ACADEMIC_CODE',
                    "Cohort label '{$label}' already exists for this program.",
                    409
                );
            }
            $data['label'] = $label;
        }

        $before = $cohort->only(['label', 'intake_year', 'description', 'start_date', 'end_date']);
        $updated = $this->cohortRepository->update(
            $cohort,
            collect($data)->only(['label', 'intake_year', 'description', 'start_date', 'end_date'])->toArray()
        );

        $this->auditLogger->record(
            'COHORT_UPDATED',
            $actor?->id,
            null,
            $before,
            $updated->only(['label', 'intake_year', 'description', 'start_date', 'end_date']),
            $ip,
            $requestId,
            null,
            'AcademicCohort',
            $updated->id
        );

        return $updated;
    }

    public function updateCohortStatus(
        AcademicCohort $cohort,
        CohortStatus $targetStatus,
        ?string $reason = null,
        ?User $actor = null,
        ?string $ip = null,
        ?string $requestId = null
    ): AcademicCohort {
        if ($targetStatus === CohortStatus::CLOSED && $this->cohortRepository->hasOpenPeriods($cohort->id)) {
            throw new AcademicDomainException(
                'ACTIVE_DEPENDENCY_EXISTS',
                'Cannot close cohort while it still has OPEN academic study periods.',
                409
            );
        }

        if (($cohort->status === CohortStatus::CLOSED || $targetStatus === CohortStatus::CLOSED) && empty(trim((string) $reason))) {
            throw new AcademicDomainException(
                'VALIDATION_ERROR',
                'A reason is required when closing or reopening a cohort.',
                422,
                ['reason' => ['Reason is mandatory when closing or reopening a cohort.']]
            );
        }

        $before = ['status' => $cohort->status->value];
        $updated = $this->cohortRepository->update($cohort, ['status' => $targetStatus]);

        $this->auditLogger->record(
            'COHORT_STATUS_CHANGED',
            $actor?->id,
            null,
            $before,
            ['status' => $updated->status->value],
            $ip,
            $requestId,
            $reason,
            'AcademicCohort',
            $updated->id
        );

        return $updated;
    }

    public function createPeriod(
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
                // SESSION_BASED program
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

                // Validate non-overlapping dates and stage chronological order within the SAME cohort
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

    public function updatePeriod(
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

    public function updatePeriodStatus(
        int $periodId,
        PeriodState $targetState,
        int $lockVersion,
        ?string $reason = null,
        ?User $actor = null,
        ?string $ip = null,
        ?string $requestId = null
    ): AcademicPeriod {
        return DB::transaction(function () use ($periodId, $targetState, $lockVersion, $reason, $actor, $ip, $requestId) {
            $period = AcademicPeriod::where('id', $periodId)->lockForUpdate()->first();
            if (! $period) {
                abort(404, 'Academic period not found.');
            }

            if ($period->lock_version !== $lockVersion) {
                throw new AcademicDomainException(
                    'VERSION_CONFLICT',
                    'Academic period changed. Refresh and retry.',
                    409
                );
            }

            $currentState = $period->state;
            if ($currentState === $targetState) {
                return $period->fresh(['program', 'cohort']);
            }

            // Transition rules:
            // DRAFT -> OPEN (requires valid active offering with active section)
            // OPEN -> CLOSED (requires reason)
            // CLOSED -> OPEN (requires reason)
            if ($currentState === PeriodState::DRAFT && $targetState === PeriodState::CLOSED) {
                throw new AcademicDomainException(
                    'PERIOD_NOT_READY',
                    'A DRAFT period cannot be closed directly before it has been opened.',
                    409
                );
            }

            if ($targetState === PeriodState::OPEN && $currentState === PeriodState::DRAFT) {
                if (! $this->periodRepository->hasActiveOfferingWithSection($period->id)) {
                    throw new AcademicDomainException(
                        'PERIOD_NOT_READY',
                        'Academic period cannot be opened until at least one active offering and active section exist.',
                        409
                    );
                }
            }

            if (
                ($currentState === PeriodState::OPEN && $targetState === PeriodState::CLOSED) ||
                ($currentState === PeriodState::CLOSED && $targetState === PeriodState::OPEN)
            ) {
                if (empty(trim((string) $reason))) {
                    throw new AcademicDomainException(
                        'VALIDATION_ERROR',
                        'A mandatory reason is required to close or reopen an academic period.',
                        422,
                        ['reason' => ['Please provide a reason for closing or reopening this academic period.']]
                    );
                }
            }

            // Also ensure cohort is ACTIVE when opening a session period
            if ($targetState === PeriodState::OPEN && $period->cohort_id !== null) {
                $cohort = AcademicCohort::where('id', $period->cohort_id)->lockForUpdate()->first();
                if ($cohort && $cohort->status === CohortStatus::DRAFT) {
                    $cohort->update(['status' => CohortStatus::ACTIVE]);
                }
            }

            $before = [
                'state' => $currentState->value,
                'lock_version' => $period->lock_version,
            ];

            $period->update([
                'state' => $targetState,
                'lock_version' => $period->lock_version + 1,
            ]);

            $updated = $period->fresh(['program', 'cohort']);

            $actionCode = match (true) {
                $currentState === PeriodState::CLOSED && $targetState === PeriodState::OPEN => 'PERIOD_REOPENED',
                $targetState === PeriodState::CLOSED => 'PERIOD_CLOSED',
                default => 'PERIOD_OPENED',
            };

            $this->auditLogger->record(
                $actionCode,
                $actor?->id,
                null,
                $before,
                [
                    'state' => $updated->state->value,
                    'lock_version' => $updated->lock_version,
                    'reason' => $reason,
                ],
                $ip,
                $requestId,
                $reason,
                'AcademicPeriod',
                $updated->id
            );

            return $updated;
        });
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

            // Check any overlap
            if ($startDate <= $existingEnd && $endDate >= $existingStart) {
                throw new AcademicDomainException(
                    'PERIOD_DATE_CONFLICT',
                    "Study period dates overlap with stage {$existing->study_year_number} ({$existingStart} to {$existingEnd}) in the same cohort.",
                    409
                );
            }

            // Check stage chronological ordering
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

