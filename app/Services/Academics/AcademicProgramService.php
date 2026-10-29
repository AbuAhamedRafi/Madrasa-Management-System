<?php

namespace App\Services\Academics;

use App\Enums\AcademicScheme;
use App\Enums\ProgramState;
use App\Exceptions\AcademicDomainException;
use App\Models\AcademicProgram;
use App\Models\AcademicTrack;
use App\Models\ProgramClass;
use App\Models\User;
use App\Repositories\Academics\AcademicProgramRepository;
use App\Repositories\Academics\AcademicTrackRepository;
use App\Repositories\Academics\ProgramClassRepository;
use App\Services\AuditTrail\AuditLogger;

class AcademicProgramService
{
    public function __construct(
        protected AcademicProgramRepository $programRepository,
        protected ProgramClassRepository $classRepository,
        protected AcademicTrackRepository $trackRepository,
        protected AuditLogger $auditLogger
    ) {}

    public function createProgram(
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

    public function updateProgram(
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

    public function updateProgramStatus(
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

    public function createClass(
        array $data,
        ?User $actor = null,
        ?string $ip = null,
        ?string $requestId = null
    ): ProgramClass {
        $program = $this->programRepository->findById((int) $data['program_id']);
        if (! $program) {
            abort(404, 'Academic program not found.');
        }

        $stageNumber = (int) $data['stage_number'];
        if ($stageNumber < 1 || $stageNumber > $program->duration_years) {
            throw new AcademicDomainException(
                'VALIDATION_ERROR',
                "Stage number must be between 1 and program duration ({$program->duration_years}).",
                422,
                ['stage_number' => ["Stage number must be between 1 and {$program->duration_years}."]]
            );
        }

        $normalizedCode = strtoupper(trim($data['code']));
        if ($this->classRepository->findByProgramAndCode($program->id, $normalizedCode)) {
            throw new AcademicDomainException(
                'DUPLICATE_ACADEMIC_CODE',
                "Class code '{$normalizedCode}' already exists in this program.",
                409,
                ['code' => ["Class code '{$normalizedCode}' already exists in this program."]]
            );
        }

        if ($this->classRepository->findByProgramAndStage($program->id, $stageNumber)) {
            throw new AcademicDomainException(
                'DUPLICATE_ACADEMIC_CODE',
                "Stage {$stageNumber} already has a defined class in this program.",
                409,
                ['stage_number' => ["Stage {$stageNumber} is already assigned in this program."]]
            );
        }

        $programClass = $this->classRepository->create([
            'program_id' => $program->id,
            'code' => $normalizedCode,
            'name_bn' => trim($data['name_bn']),
            'name_en' => trim($data['name_en']),
            'stage_number' => $stageNumber,
            'sort_order' => $data['sort_order'] ?? $stageNumber,
            'is_active' => $data['is_active'] ?? true,
        ]);

        $this->auditLogger->record(
            'PROGRAM_CLASS_CREATED',
            $actor?->id,
            null,
            null,
            $programClass->only(['id', 'program_id', 'code', 'stage_number', 'name_bn', 'name_en']),
            $ip,
            $requestId,
            null,
            'ProgramClass',
            $programClass->id
        );

        return $programClass;
    }

    public function updateClass(
        ProgramClass $programClass,
        array $data,
        ?User $actor = null,
        ?string $ip = null,
        ?string $requestId = null
    ): ProgramClass {
        $hasOfferings = $this->classRepository->hasOfferings($programClass->id);

        if (isset($data['program_id']) && (int) $data['program_id'] !== $programClass->program_id) {
            throw new AcademicDomainException(
                'PROGRAM_RELATION_MISMATCH',
                'Cannot move a program class to another program.',
                422
            );
        }

        if (isset($data['stage_number']) && (int) $data['stage_number'] !== $programClass->stage_number) {
            if ($hasOfferings) {
                throw new AcademicDomainException(
                    'ACTIVE_DEPENDENCY_EXISTS',
                    'Cannot change stage number of a class after offerings exist.',
                    409
                );
            }
            $program = $programClass->program;
            $stageNumber = (int) $data['stage_number'];
            if ($stageNumber < 1 || $stageNumber > $program->duration_years) {
                throw new AcademicDomainException(
                    'VALIDATION_ERROR',
                    "Stage number must be between 1 and {$program->duration_years}.",
                    422
                );
            }
            if ($this->classRepository->findByProgramAndStage($programClass->program_id, $stageNumber, $programClass->id)) {
                throw new AcademicDomainException(
                    'DUPLICATE_ACADEMIC_CODE',
                    "Stage {$stageNumber} is already assigned in this program.",
                    409
                );
            }
        }

        if (isset($data['code'])) {
            $normalizedCode = strtoupper(trim($data['code']));
            if ($normalizedCode !== $programClass->code && $hasOfferings) {
                throw new AcademicDomainException(
                    'ACTIVE_DEPENDENCY_EXISTS',
                    'Cannot change class code after offerings exist.',
                    409
                );
            }
            if ($this->classRepository->findByProgramAndCode($programClass->program_id, $normalizedCode, $programClass->id)) {
                throw new AcademicDomainException(
                    'DUPLICATE_ACADEMIC_CODE',
                    "Class code '{$normalizedCode}' already exists in this program.",
                    409
                );
            }
            $data['code'] = $normalizedCode;
        }

        $before = $programClass->only(['code', 'name_bn', 'name_en', 'stage_number', 'sort_order']);
        $updated = $this->classRepository->update(
            $programClass,
            collect($data)->only(['code', 'name_bn', 'name_en', 'stage_number', 'sort_order'])->toArray()
        );

        $this->auditLogger->record(
            'PROGRAM_CLASS_UPDATED',
            $actor?->id,
            null,
            $before,
            $updated->only(['code', 'name_bn', 'name_en', 'stage_number', 'sort_order']),
            $ip,
            $requestId,
            null,
            'ProgramClass',
            $updated->id
        );

        return $updated;
    }

    public function updateClassStatus(
        ProgramClass $programClass,
        bool $isActive,
        ?string $reason = null,
        ?User $actor = null,
        ?string $ip = null,
        ?string $requestId = null
    ): ProgramClass {
        if (! $isActive && $this->classRepository->hasActiveOfferings($programClass->id)) {
            throw new AcademicDomainException(
                'ACTIVE_DEPENDENCY_EXISTS',
                'Cannot deactivate class while it has active period offerings.',
                409
            );
        }

        $before = ['is_active' => $programClass->is_active];
        $updated = $this->classRepository->update($programClass, ['is_active' => $isActive]);

        $this->auditLogger->record(
            'PROGRAM_CLASS_STATUS_CHANGED',
            $actor?->id,
            null,
            $before,
            ['is_active' => $updated->is_active],
            $ip,
            $requestId,
            $reason,
            'ProgramClass',
            $updated->id
        );

        return $updated;
    }

    public function createTrack(
        array $data,
        ?User $actor = null,
        ?string $ip = null,
        ?string $requestId = null
    ): AcademicTrack {
        $program = $this->programRepository->findById((int) $data['program_id']);
        if (! $program) {
            abort(404, 'Academic program not found.');
        }

        $normalizedCode = strtoupper(trim($data['code']));
        if ($this->trackRepository->findByProgramAndCode($program->id, $normalizedCode)) {
            throw new AcademicDomainException(
                'DUPLICATE_ACADEMIC_CODE',
                "Track code '{$normalizedCode}' already exists in this program.",
                409
            );
        }

        $track = $this->trackRepository->create([
            'program_id' => $program->id,
            'code' => $normalizedCode,
            'name_bn' => trim($data['name_bn']),
            'name_en' => trim($data['name_en']),
            'is_active' => $data['is_active'] ?? true,
        ]);

        $this->auditLogger->record(
            'ACADEMIC_TRACK_CREATED',
            $actor?->id,
            null,
            null,
            $track->only(['id', 'program_id', 'code', 'name_bn', 'name_en']),
            $ip,
            $requestId,
            null,
            'AcademicTrack',
            $track->id
        );

        return $track;
    }

    public function updateTrack(
        AcademicTrack $track,
        array $data,
        ?User $actor = null,
        ?string $ip = null,
        ?string $requestId = null
    ): AcademicTrack {
        if (isset($data['program_id']) && (int) $data['program_id'] !== $track->program_id) {
            throw new AcademicDomainException(
                'PROGRAM_RELATION_MISMATCH',
                'Cannot transfer an academic track to another program.',
                422
            );
        }

        if (isset($data['code'])) {
            $normalizedCode = strtoupper(trim($data['code']));
            if ($normalizedCode !== $track->code && $this->trackRepository->hasOfferings($track->id)) {
                throw new AcademicDomainException(
                    'ACTIVE_DEPENDENCY_EXISTS',
                    'Cannot change track code after offerings exist.',
                    409
                );
            }
            if ($this->trackRepository->findByProgramAndCode($track->program_id, $normalizedCode, $track->id)) {
                throw new AcademicDomainException(
                    'DUPLICATE_ACADEMIC_CODE',
                    "Track code '{$normalizedCode}' already exists in this program.",
                    409
                );
            }
            $data['code'] = $normalizedCode;
        }

        $before = $track->only(['code', 'name_bn', 'name_en']);
        $updated = $this->trackRepository->update(
            $track,
            collect($data)->only(['code', 'name_bn', 'name_en'])->toArray()
        );

        $this->auditLogger->record(
            'ACADEMIC_TRACK_UPDATED',
            $actor?->id,
            null,
            $before,
            $updated->only(['code', 'name_bn', 'name_en']),
            $ip,
            $requestId,
            null,
            'AcademicTrack',
            $updated->id
        );

        return $updated;
    }

    public function updateTrackStatus(
        AcademicTrack $track,
        bool $isActive,
        ?string $reason = null,
        ?User $actor = null,
        ?string $ip = null,
        ?string $requestId = null
    ): AcademicTrack {
        if (! $isActive && $this->trackRepository->hasActiveOfferings($track->id)) {
            throw new AcademicDomainException(
                'ACTIVE_DEPENDENCY_EXISTS',
                'Cannot deactivate track while it has active period offerings.',
                409
            );
        }

        $before = ['is_active' => $track->is_active];
        $updated = $this->trackRepository->update($track, ['is_active' => $isActive]);

        $this->auditLogger->record(
            'ACADEMIC_TRACK_STATUS_CHANGED',
            $actor?->id,
            null,
            $before,
            ['is_active' => $updated->is_active],
            $ip,
            $requestId,
            $reason,
            'AcademicTrack',
            $updated->id
        );

        return $updated;
    }
}

