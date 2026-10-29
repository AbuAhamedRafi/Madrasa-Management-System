<?php

namespace App\Services\Academics;

use App\Exceptions\AcademicDomainException;
use App\Models\AcademicTrack;
use App\Models\User;
use App\Repositories\Academics\AcademicProgramRepository;
use App\Repositories\Academics\AcademicTrackRepository;
use App\Services\AuditTrail\AuditLogger;

class AcademicTrackService
{
    public function __construct(
        protected AcademicProgramRepository $programRepository,
        protected AcademicTrackRepository $trackRepository,
        protected AuditLogger $auditLogger
    ) {}

    public function create(
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

    public function update(
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

    public function updateStatus(
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
