<?php

namespace App\Services\Academics;

use App\Enums\AcademicScheme;
use App\Enums\PeriodState;
use App\Exceptions\AcademicDomainException;
use App\Models\AcademicOffering;
use App\Models\AcademicPeriod;
use App\Models\AcademicTrack;
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

    public function create(
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

    public function update(
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
}
