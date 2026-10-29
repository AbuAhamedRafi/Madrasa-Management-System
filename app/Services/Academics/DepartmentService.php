<?php

namespace App\Services\Academics;

use App\Exceptions\AcademicDomainException;
use App\Models\Department;
use App\Models\User;
use App\Repositories\Academics\DepartmentRepository;
use App\Services\AuditTrail\AuditLogger;

class DepartmentService
{
    public function __construct(
        protected DepartmentRepository $departmentRepository,
        protected AuditLogger $auditLogger
    ) {}

    public function create(
        array $data,
        ?User $actor = null,
        ?string $ip = null,
        ?string $requestId = null
    ): Department {
        $normalizedCode = strtoupper(trim($data['code']));

        if ($this->departmentRepository->findByNormalizedCode($normalizedCode)) {
            throw new AcademicDomainException(
                'DUPLICATE_ACADEMIC_CODE',
                "Department code '{$normalizedCode}' already exists.",
                409,
                ['code' => ["Department code '{$normalizedCode}' is already in use."]]
            );
        }

        $department = $this->departmentRepository->create([
            'code' => $normalizedCode,
            'name_bn' => trim($data['name_bn']),
            'name_en' => trim($data['name_en']),
            'description_bn' => $data['description_bn'] ?? null,
            'description_en' => $data['description_en'] ?? null,
            'sort_order' => $data['sort_order'] ?? 0,
            'is_active' => $data['is_active'] ?? true,
        ]);

        $this->auditLogger->record(
            'DEPARTMENT_CREATED',
            $actor?->id,
            null,
            null,
            $department->only(['id', 'code', 'name_bn', 'name_en', 'is_active']),
            $ip,
            $requestId,
            null,
            'Department',
            $department->id
        );

        return $department;
    }

    public function update(
        Department $department,
        array $data,
        ?User $actor = null,
        ?string $ip = null,
        ?string $requestId = null
    ): Department {
        if (isset($data['code']) && strtoupper(trim($data['code'])) !== $department->code) {
            throw new AcademicDomainException(
                'VALIDATION_ERROR',
                'Department code is immutable after creation.',
                422,
                ['code' => ['Department code is immutable after creation.']]
            );
        }

        $before = $department->only(['name_bn', 'name_en', 'description_bn', 'description_en', 'sort_order']);
        $updated = $this->departmentRepository->update(
            $department,
            collect($data)->only(['name_bn', 'name_en', 'description_bn', 'description_en', 'sort_order'])->toArray()
        );

        $this->auditLogger->record(
            'DEPARTMENT_UPDATED',
            $actor?->id,
            null,
            $before,
            $updated->only(['name_bn', 'name_en', 'description_bn', 'description_en', 'sort_order']),
            $ip,
            $requestId,
            null,
            'Department',
            $updated->id
        );

        return $updated;
    }

    public function updateStatus(
        Department $department,
        bool $isActive,
        ?string $reason = null,
        ?User $actor = null,
        ?string $ip = null,
        ?string $requestId = null
    ): Department {
        if (! $isActive && $this->departmentRepository->hasActiveProgramsOrOpenPeriods($department->id)) {
            throw new AcademicDomainException(
                'ACTIVE_DEPENDENCY_EXISTS',
                'Cannot deactivate department with active programs or open academic periods.',
                409
            );
        }

        $before = ['is_active' => $department->is_active];
        $updated = $this->departmentRepository->update($department, ['is_active' => $isActive]);

        $this->auditLogger->record(
            'DEPARTMENT_STATUS_CHANGED',
            $actor?->id,
            null,
            $before,
            ['is_active' => $updated->is_active],
            $ip,
            $requestId,
            $reason,
            'Department',
            $updated->id
        );

        return $updated;
    }
}

