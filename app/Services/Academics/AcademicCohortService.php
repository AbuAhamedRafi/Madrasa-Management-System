<?php

namespace App\Services\Academics;

use App\Enums\AcademicScheme;
use App\Enums\CohortStatus;
use App\Exceptions\AcademicDomainException;
use App\Models\AcademicCohort;
use App\Models\User;
use App\Repositories\Academics\AcademicCohortRepository;
use App\Repositories\Academics\AcademicProgramRepository;
use App\Services\AuditTrail\AuditLogger;

class AcademicCohortService
{
    public function __construct(
        protected AcademicProgramRepository $programRepository,
        protected AcademicCohortRepository $cohortRepository,
        protected AuditLogger $auditLogger
    ) {}

    public function create(
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
            // Never infer dates from cohort label
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

    public function update(
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

    public function updateStatus(
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
}

