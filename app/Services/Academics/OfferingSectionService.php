<?php

namespace App\Services\Academics;

use App\Enums\PeriodState;
use App\Exceptions\AcademicDomainException;
use App\Models\AcademicOffering;
use App\Models\OfferingSection;
use App\Models\User;
use App\Repositories\Academics\OfferingSectionRepository;
use App\Services\AuditTrail\AuditLogger;

class OfferingSectionService
{
    public function __construct(
        protected OfferingSectionRepository $sectionRepository,
        protected AuditLogger $auditLogger
    ) {}

    public function create(
        AcademicOffering $offering,
        array $data,
        ?User $actor = null,
        ?string $ip = null,
        ?string $requestId = null
    ): OfferingSection {
        $period = $offering->period;
        if ($period && $period->state === PeriodState::CLOSED) {
            throw new AcademicDomainException(
                'CLOSED_PERIOD',
                'Cannot add sections to an offering inside a CLOSED academic period.',
                409
            );
        }

        $normalizedCode = strtoupper(trim($data['code']));
        if ($this->sectionRepository->findByOfferingAndCode($offering->id, $normalizedCode)) {
            throw new AcademicDomainException(
                'DUPLICATE_ACADEMIC_CODE',
                "Section code '{$normalizedCode}' already exists in this offering.",
                409,
                ['code' => ["Section code '{$normalizedCode}' already exists in this offering."]]
            );
        }

        $capacity = (int) $data['capacity'];
        if ($capacity <= 0) {
            throw new AcademicDomainException(
                'VALIDATION_ERROR',
                'Section capacity must be a positive integer.',
                422,
                ['capacity' => ['Section capacity must be greater than zero.']]
            );
        }

        $section = $this->sectionRepository->create([
            'academic_offering_id' => $offering->id,
            'code' => $normalizedCode,
            'name_bn' => trim($data['name_bn']),
            'name_en' => trim($data['name_en']),
            'capacity' => $capacity,
            'is_active' => $data['is_active'] ?? true,
        ]);

        $this->auditLogger->record(
            'OFFERING_SECTION_CREATED',
            $actor?->id,
            null,
            null,
            $section->only(['id', 'academic_offering_id', 'code', 'name_bn', 'name_en', 'capacity', 'is_active']),
            $ip,
            $requestId,
            null,
            'OfferingSection',
            $section->id
        );

        return $section;
    }

    public function update(
        OfferingSection $section,
        array $data,
        ?User $actor = null,
        ?string $ip = null,
        ?string $requestId = null
    ): OfferingSection {
        $period = $section->offering?->period;
        if ($period && $period->state === PeriodState::CLOSED) {
            throw new AcademicDomainException(
                'CLOSED_PERIOD',
                'Cannot modify a section inside a CLOSED academic period.',
                409
            );
        }

        if (isset($data['code'])) {
            $normalizedCode = strtoupper(trim($data['code']));
            if ($this->sectionRepository->findByOfferingAndCode($section->academic_offering_id, $normalizedCode, $section->id)) {
                throw new AcademicDomainException(
                    'DUPLICATE_ACADEMIC_CODE',
                    "Section code '{$normalizedCode}' already exists in this offering.",
                    409
                );
            }
            $data['code'] = $normalizedCode;
        }

        if (isset($data['capacity']) && (int) $data['capacity'] <= 0) {
            throw new AcademicDomainException(
                'VALIDATION_ERROR',
                'Section capacity must be a positive integer.',
                422,
                ['capacity' => ['Section capacity must be greater than zero.']]
            );
        }

        $before = $section->only(['code', 'name_bn', 'name_en', 'capacity', 'is_active']);
        $updated = $this->sectionRepository->update(
            $section,
            collect($data)->only(['code', 'name_bn', 'name_en', 'capacity', 'is_active'])->toArray()
        );

        $this->auditLogger->record(
            'OFFERING_SECTION_UPDATED',
            $actor?->id,
            null,
            $before,
            $updated->only(['code', 'name_bn', 'name_en', 'capacity', 'is_active']),
            $ip,
            $requestId,
            null,
            'OfferingSection',
            $updated->id
        );

        return $updated;
    }

    public function updateStatus(
        OfferingSection $section,
        bool $isActive,
        ?string $reason = null,
        ?User $actor = null,
        ?string $ip = null,
        ?string $requestId = null
    ): OfferingSection {
        $period = $section->offering?->period;
        if ($period && $period->state === PeriodState::CLOSED) {
            throw new AcademicDomainException(
                'CLOSED_PERIOD',
                'Cannot change section status inside a CLOSED academic period.',
                409
            );
        }

        $before = ['is_active' => $section->is_active];
        $updated = $this->sectionRepository->update($section, ['is_active' => $isActive]);

        $this->auditLogger->record(
            'OFFERING_SECTION_STATUS_CHANGED',
            $actor?->id,
            null,
            $before,
            ['is_active' => $updated->is_active],
            $ip,
            $requestId,
            $reason,
            'OfferingSection',
            $updated->id
        );

        return $updated;
    }
}
