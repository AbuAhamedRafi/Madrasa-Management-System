<?php

namespace App\Services\Academics;

use App\Exceptions\AcademicDomainException;
use App\Models\EducationAuthority;
use App\Models\User;
use App\Repositories\Academics\AuthorityRepository;
use App\Services\AuditTrail\AuditLogger;

class AuthorityService
{
    public function __construct(
        protected AuthorityRepository $authorityRepository,
        protected AuditLogger $auditLogger
    ) {}

    public function create(
        array $data,
        ?User $actor = null,
        ?string $ip = null,
        ?string $requestId = null
    ): EducationAuthority {
        $normalizedCode = strtoupper(trim($data['code']));

        if ($this->authorityRepository->findByNormalizedCode($normalizedCode)) {
            throw new AcademicDomainException(
                'DUPLICATE_ACADEMIC_CODE',
                "Authority code '{$normalizedCode}' already exists.",
                409,
                ['code' => ["Authority code '{$normalizedCode}' is already in use."]]
            );
        }

        $authority = $this->authorityRepository->create([
            'code' => $normalizedCode,
            'name_bn' => trim($data['name_bn']),
            'name_en' => trim($data['name_en']),
            'is_active' => $data['is_active'] ?? true,
            'sort_order' => $data['sort_order'] ?? 0,
        ]);

        $this->auditLogger->record(
            'AUTHORITY_CREATED',
            $actor?->id,
            null,
            null,
            $authority->only(['id', 'code', 'name_bn', 'name_en', 'is_active']),
            $ip,
            $requestId,
            null,
            'EducationAuthority',
            $authority->id
        );

        return $authority;
    }

    public function update(
        EducationAuthority $authority,
        array $data,
        ?User $actor = null,
        ?string $ip = null,
        ?string $requestId = null
    ): EducationAuthority {
        if (isset($data['code'])) {
            $normalizedCode = strtoupper(trim($data['code']));
            if ($normalizedCode !== $authority->code) {
                if ($this->authorityRepository->hasReferencedLevels($authority->id)) {
                    throw new AcademicDomainException(
                        'ACTIVE_DEPENDENCY_EXISTS',
                        'Authority code cannot be modified once referenced by education levels.',
                        409
                    );
                }
                if ($this->authorityRepository->findByNormalizedCode($normalizedCode, $authority->id)) {
                    throw new AcademicDomainException(
                        'DUPLICATE_ACADEMIC_CODE',
                        "Authority code '{$normalizedCode}' already exists.",
                        409
                    );
                }
                $data['code'] = $normalizedCode;
            }
        }

        $before = $authority->only(['code', 'name_bn', 'name_en', 'sort_order']);
        $updated = $this->authorityRepository->update(
            $authority,
            collect($data)->only(['code', 'name_bn', 'name_en', 'sort_order'])->toArray()
        );

        $this->auditLogger->record(
            'AUTHORITY_UPDATED',
            $actor?->id,
            null,
            $before,
            $updated->only(['code', 'name_bn', 'name_en', 'sort_order']),
            $ip,
            $requestId,
            null,
            'EducationAuthority',
            $updated->id
        );

        return $updated;
    }

    public function updateStatus(
        EducationAuthority $authority,
        bool $isActive,
        ?string $reason = null,
        ?User $actor = null,
        ?string $ip = null,
        ?string $requestId = null
    ): EducationAuthority {
        if (! $isActive && $this->authorityRepository->hasActiveLevels($authority->id)) {
            throw new AcademicDomainException(
                'ACTIVE_DEPENDENCY_EXISTS',
                'Cannot deactivate authority while it has active education levels.',
                409
            );
        }

        $before = ['is_active' => $authority->is_active];
        $updated = $this->authorityRepository->update($authority, ['is_active' => $isActive]);

        $this->auditLogger->record(
            'AUTHORITY_STATUS_CHANGED',
            $actor?->id,
            null,
            $before,
            ['is_active' => $updated->is_active],
            $ip,
            $requestId,
            $reason,
            'EducationAuthority',
            $updated->id
        );

        return $updated;
    }
}
