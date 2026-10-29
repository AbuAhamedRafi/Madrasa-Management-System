<?php

namespace App\Services\Academics;

use App\Exceptions\AcademicDomainException;
use App\Models\ProgramClass;
use App\Models\User;
use App\Repositories\Academics\AcademicProgramRepository;
use App\Repositories\Academics\ProgramClassRepository;
use App\Services\AuditTrail\AuditLogger;

class ProgramClassService
{
    public function __construct(
        protected AcademicProgramRepository $programRepository,
        protected ProgramClassRepository $classRepository,
        protected AuditLogger $auditLogger
    ) {}

    public function create(
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

    public function update(
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

    public function updateStatus(
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
}
