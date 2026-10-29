<?php

namespace App\Services\Academics;

use App\Exceptions\AcademicDomainException;
use App\Models\EducationLevel;
use App\Models\User;
use App\Repositories\Academics\EducationLevelRepository;
use App\Services\AuditTrail\AuditLogger;

class EducationLevelService
{
    public function __construct(
        protected EducationLevelRepository $levelRepository,
        protected AuditLogger $auditLogger
    ) {}

    public function create(
        array $data,
        ?User $actor = null,
        ?string $ip = null,
        ?string $requestId = null
    ): EducationLevel {
        $authorityId = (int) $data['authority_id'];
        $normalizedCode = strtoupper(trim($data['code']));

        if ($this->levelRepository->findByAuthorityAndCode($authorityId, $normalizedCode)) {
            throw new AcademicDomainException(
                'DUPLICATE_ACADEMIC_CODE',
                "Education level code '{$normalizedCode}' already exists under this authority.",
                409,
                ['code' => ["Level code '{$normalizedCode}' already exists for this authority."]]
            );
        }

        $level = $this->levelRepository->create([
            'authority_id' => $authorityId,
            'code' => $normalizedCode,
            'name_bn' => trim($data['name_bn']),
            'name_en' => trim($data['name_en']),
            'is_active' => $data['is_active'] ?? true,
            'sort_order' => $data['sort_order'] ?? 0,
        ]);

        $this->auditLogger->record(
            'EDUCATION_LEVEL_CREATED',
            $actor?->id,
            null,
            null,
            $level->only(['id', 'authority_id', 'code', 'name_bn', 'name_en', 'is_active']),
            $ip,
            $requestId,
            null,
            'EducationLevel',
            $level->id
        );

        return $level;
    }

    public function update(
        EducationLevel $level,
        array $data,
        ?User $actor = null,
        ?string $ip = null,
        ?string $requestId = null
    ): EducationLevel {
        $referenced = $this->levelRepository->hasReferencedPrograms($level->id);

        if (isset($data['authority_id']) && (int) $data['authority_id'] !== $level->authority_id && $referenced) {
            throw new AcademicDomainException(
                'ACTIVE_DEPENDENCY_EXISTS',
                'Cannot change authority of an education level once referenced by academic programs.',
                409
            );
        }

        $targetAuthorityId = isset($data['authority_id']) ? (int) $data['authority_id'] : $level->authority_id;

        if (isset($data['code'])) {
            $normalizedCode = strtoupper(trim($data['code']));
            if ($normalizedCode !== $level->code && $referenced) {
                throw new AcademicDomainException(
                    'ACTIVE_DEPENDENCY_EXISTS',
                    'Cannot change education level code once referenced by academic programs.',
                    409
                );
            }
            if ($this->levelRepository->findByAuthorityAndCode($targetAuthorityId, $normalizedCode, $level->id)) {
                throw new AcademicDomainException(
                    'DUPLICATE_ACADEMIC_CODE',
                    "Education level code '{$normalizedCode}' already exists under this authority.",
                    409
                );
            }
            $data['code'] = $normalizedCode;
        }

        $before = $level->only(['authority_id', 'code', 'name_bn', 'name_en', 'sort_order']);
        $updated = $this->levelRepository->update(
            $level,
            collect($data)->only(['authority_id', 'code', 'name_bn', 'name_en', 'sort_order'])->toArray()
        );

        $this->auditLogger->record(
            'EDUCATION_LEVEL_UPDATED',
            $actor?->id,
            null,
            $before,
            $updated->only(['authority_id', 'code', 'name_bn', 'name_en', 'sort_order']),
            $ip,
            $requestId,
            null,
            'EducationLevel',
            $updated->id
        );

        return $updated;
    }

    public function updateStatus(
        EducationLevel $level,
        bool $isActive,
        ?string $reason = null,
        ?User $actor = null,
        ?string $ip = null,
        ?string $requestId = null
    ): EducationLevel {
        if (! $isActive && $this->levelRepository->hasActivePrograms($level->id)) {
            throw new AcademicDomainException(
                'ACTIVE_DEPENDENCY_EXISTS',
                'Cannot deactivate education level while referenced by active or draft programs.',
                409
            );
        }

        $before = ['is_active' => $level->is_active];
        $updated = $this->levelRepository->update($level, ['is_active' => $isActive]);

        $this->auditLogger->record(
            'EDUCATION_LEVEL_STATUS_CHANGED',
            $actor?->id,
            null,
            $before,
            ['is_active' => $updated->is_active],
            $ip,
            $requestId,
            $reason,
            'EducationLevel',
            $updated->id
        );

        return $updated;
    }
}
