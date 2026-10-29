<?php

namespace App\Services\Academics;

use App\Enums\AcademicScheme;
use App\Enums\ProgramState;
use App\Exceptions\AcademicDomainException;
use App\Models\AcademicProgram;
use App\Models\User;
use App\Repositories\Academics\AcademicProgramRepository;
use App\Services\AuditTrail\AuditLogger;

class AcademicProgramService
{
    public function __construct(
        protected AcademicProgramRepository $programRepository,
        protected AuditLogger $auditLogger
    ) {}

    public function create(
        array $data,
        ?User $actor = null,
        ?string $ip = null,
        ?string $requestId = null
    ): AcademicProgram {
        $normalizedCode = strtoupper(trim($data['code']));

        if ($this->programRepository->findByNormalizedCode($normalizedCode)) {
            throw new AcademicDomainException(
                'DUPLICATE_ACADEMIC_CODE',
                "Program code '{$normalizedCode}' already exists.",
                409,
                ['code' => ["Program code '{$normalizedCode}' is already in use."]]
            );
        }

        $program = $this->programRepository->create([
            'code' => $normalizedCode,
            'department_id' => (int) $data['department_id'],
            'education_level_id' => (int) $data['education_level_id'],
            'name_bn' => trim($data['name_bn']),
            'name_en' => trim($data['name_en']),
            'description_bn' => $data['description_bn'] ?? null,
            'description_en' => $data['description_en'] ?? null,
            'scheme' => AcademicScheme::from(strtoupper(trim($data['scheme']))),
            'duration_years' => (int) $data['duration_years'],
            'state' => isset($data['state']) ? ProgramState::from(strtoupper(trim($data['state']))) : ProgramState::DRAFT,
        ]);

        $this->auditLogger->record(
            'PROGRAM_CREATED',
            $actor?->id,
            null,
            null,
            $program->only(['id', 'code', 'scheme', 'duration_years', 'state']),
            $ip,
            $requestId,
            null,
            'AcademicProgram',
            $program->id
        );

        return $program;
    }

    public function update(
        AcademicProgram $program,
        array $data,
        ?User $actor = null,
        ?string $ip = null,
        ?string $requestId = null
    ): AcademicProgram {
        $hasDependencies = $this->programRepository->hasDependentCohortsOrPeriods($program->id);

        if (isset($data['code']) && strtoupper(trim($data['code'])) !== $program->code) {
            throw new AcademicDomainException(
                'VALIDATION_ERROR',
                'Program code is immutable after creation.',
                422,
                ['code' => ['Program code is immutable after creation.']]
            );
        }

        if (isset($data['scheme'])) {
            $incomingScheme = strtoupper(trim($data['scheme']));
            if ($incomingScheme !== $program->scheme->value) {
                if ($hasDependencies) {
                    throw new AcademicDomainException(
                        'ACADEMIC_SCHEME_IMMUTABLE',
                        'Cannot change academic scheme after cohorts or periods have been created.',
                        409
                    );
                }
                $data['scheme'] = AcademicScheme::from($incomingScheme);
            } else {
                unset($data['scheme']);
            }
        }

        if (isset($data['duration_years'])) {
            $maxClassStage = $program->classes()->max('stage_number') ?? 0;
            $maxPeriodStage = $program->periods()->max('study_year_number') ?? 0;
            $requiredMinStages = max($maxClassStage, $maxPeriodStage);
            if ((int) $data['duration_years'] < $requiredMinStages) {
                throw new AcademicDomainException(
                    'VALIDATION_ERROR',
                    "Duration years cannot be smaller than existing stage number ({$requiredMinStages}).",
                    422,
                    ['duration_years' => ["Duration must be at least {$requiredMinStages} to cover existing stages."]]
                );
            }
        }

        $before = $program->only(['name_bn', 'name_en', 'description_bn', 'description_en', 'department_id', 'education_level_id', 'scheme', 'duration_years']);
        $updated = $this->programRepository->update(
            $program,
            collect($data)->only(['name_bn', 'name_en', 'description_bn', 'description_en', 'department_id', 'education_level_id', 'scheme', 'duration_years'])->toArray()
        );

        $this->auditLogger->record(
            'PROGRAM_UPDATED',
            $actor?->id,
            null,
            $before,
            $updated->only(['name_bn', 'name_en', 'description_bn', 'description_en', 'department_id', 'education_level_id', 'scheme', 'duration_years']),
            $ip,
            $requestId,
            null,
            'AcademicProgram',
            $updated->id
        );

        return $updated;
    }

    public function updateStatus(
        AcademicProgram $program,
        ProgramState $newState,
        ?string $reason = null,
        ?User $actor = null,
        ?string $ip = null,
        ?string $requestId = null
    ): AcademicProgram {
        if ($newState === ProgramState::INACTIVE && $this->programRepository->hasOpenPeriodsOrActiveCohorts($program->id)) {
            throw new AcademicDomainException(
                'ACTIVE_DEPENDENCY_EXISTS',
                'Cannot inactivate program while it has active cohorts or open periods.',
                409
            );
        }

        $before = ['state' => $program->state->value];
        $updated = $this->programRepository->update($program, ['state' => $newState]);

        $this->auditLogger->record(
            'PROGRAM_STATUS_CHANGED',
            $actor?->id,
            null,
            $before,
            ['state' => $updated->state->value],
            $ip,
            $requestId,
            $reason,
            'AcademicProgram',
            $updated->id
        );

        return $updated;
    }
}
