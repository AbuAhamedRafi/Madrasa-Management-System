<?php

namespace App\Services\Academics;

use App\Enums\AcademicScheme;
use App\Enums\PeriodState;
use App\Exceptions\AcademicDomainException;
use App\Models\AcademicOffering;
use App\Models\AcademicPeriod;
use App\Models\AcademicTrack;
use App\Models\OfferingSection;
use App\Models\ProgramClass;
use App\Models\User;
use App\Repositories\Academics\AcademicOfferingRepository;
use App\Services\AuditTrail\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class AcademicOfferingService
{
    public function __construct(
        protected AcademicOfferingRepository $offeringRepository,
        protected AuditLogger $auditLogger
    ) {}

    public function createOffering(
        array $data,
        ?User $actor = null,
        ?string $ip = null,
        ?string $requestId = null
    ): AcademicOffering {
        return DB::transaction(function () use ($data, $actor, $ip, $requestId) {
            $period = AcademicPeriod::with('program')->where('id', (int) $data['academic_period_id'])->lockForUpdate()->first();
            if (! $period) {
                abort(404, 'Academic period not found.');
            }

            if ($period->state === PeriodState::CLOSED) {
                throw new AcademicDomainException(
                    'CLOSED_PERIOD',
                    'Cannot create offerings inside a CLOSED academic period.',
                    409
                );
            }

            $programClass = ProgramClass::find((int) $data['program_class_id']);
            if (! $programClass || $programClass->program_id !== $period->program_id) {
                throw new AcademicDomainException(
                    'PROGRAM_RELATION_MISMATCH',
                    'Selected program class does not belong to the academic period\'s program.',
                    422,
                    ['program_class_id' => ['Selected class belongs to a different academic program.']]
                );
            }

            // Invariant 5: SESSION_BASED offerings must use the class whose stage_number matches period.study_year_number
            if ($period->program->scheme === AcademicScheme::SESSION_BASED) {
                if ($programClass->stage_number !== $period->study_year_number) {
                    throw new AcademicDomainException(
                        'PERIOD_STAGE_MISMATCH',
                        "Session period stage ({$period->study_year_number}) does not match class stage ({$programClass->stage_number}).",
                        422,
                        ['program_class_id' => ['Class stage must match the session period\'s study_year_number.']]
                    );
                }
            }

            $trackId = ! empty($data['academic_track_id']) ? (int) $data['academic_track_id'] : null;
            if ($trackId !== null) {
                $track = AcademicTrack::find($trackId);
                if (! $track || $track->program_id !== $period->program_id) {
                    throw new AcademicDomainException(
                        'PROGRAM_RELATION_MISMATCH',
                        'Selected academic track does not belong to the academic period\'s program.',
                        422,
                        ['academic_track_id' => ['Selected track belongs to a different academic program.']]
                    );
                }
            }

            if ($this->offeringRepository->findIdentity($period->id, $programClass->id, $trackId)) {
                throw new AcademicDomainException(
                    'DUPLICATE_ACADEMIC_CODE',
                    'This class/track offering already exists in the selected academic period.',
                    409
                );
            }

            try {
                $offering = $this->offeringRepository->create([
                    'academic_period_id' => $period->id,
                    'program_class_id' => $programClass->id,
                    'academic_track_id' => $trackId,
                    'is_active' => $data['is_active'] ?? true,
                ]);
            } catch (QueryException $e) {
                throw new AcademicDomainException(
                    'DUPLICATE_ACADEMIC_CODE',
                    'Duplicate period offering rejected under concurrent submission.',
                    409
                );
            }

            $this->auditLogger->record(
                'OFFERING_CREATED',
                $actor?->id,
                null,
                null,
                $offering->only(['id', 'academic_period_id', 'program_class_id', 'academic_track_id', 'is_active']),
                $ip,
                $requestId,
                null,
                'AcademicOffering',
                $offering->id
            );

            return $offering;
        });
    }

    public function updateOffering(
        AcademicOffering $offering,
        array $data,
        ?User $actor = null,
        ?string $ip = null,
        ?string $requestId = null
    ): AcademicOffering {
        $period = $offering->period;
        if ($period && $period->state === PeriodState::CLOSED) {
            throw new AcademicDomainException(
                'CLOSED_PERIOD',
                'Cannot modify an offering inside a CLOSED academic period.',
                409
            );
        }

        if (isset($data['is_active']) && ! $data['is_active'] && $period && $period->state === PeriodState::OPEN) {
            // Check if this is the last active offering with an active section in an OPEN period
            $otherActiveExists = AcademicOffering::where('academic_period_id', $period->id)
                ->where('id', '!=', $offering->id)
                ->where('is_active', true)
                ->whereHas('sections', fn ($q) => $q->where('is_active', true))
                ->exists();

            if (! $otherActiveExists) {
                throw new AcademicDomainException(
                    'ACTIVE_DEPENDENCY_EXISTS',
                    'Cannot deactivate the only active offering of an OPEN academic period.',
                    409
                );
            }
        }

        $before = ['is_active' => $offering->is_active];
        $updated = $this->offeringRepository->update(
            $offering,
            collect($data)->only(['is_active'])->toArray()
        );

        $this->auditLogger->record(
            'OFFERING_UPDATED',
            $actor?->id,
            null,
            $before,
            ['is_active' => $updated->is_active],
            $ip,
            $requestId,
            null,
            'AcademicOffering',
            $updated->id
        );

        return $updated;
    }

    public function createSection(
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
        if ($this->offeringRepository->findSectionByOfferingAndCode($offering->id, $normalizedCode)) {
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

        $section = $this->offeringRepository->createSection([
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

    public function updateSection(
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
            if ($this->offeringRepository->findSectionByOfferingAndCode($section->academic_offering_id, $normalizedCode, $section->id)) {
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
        $updated = $this->offeringRepository->updateSection(
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

    public function updateSectionStatus(
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
        $updated = $this->offeringRepository->updateSection($section, ['is_active' => $isActive]);

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

