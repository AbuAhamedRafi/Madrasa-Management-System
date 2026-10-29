<?php

namespace App\Repositories\Academics;

use App\Models\AcademicTrack;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class AcademicTrackRepository
{
    public function paginate(
        int $programId,
        ?bool $active = null,
        int $page = 1,
        int $pageSize = 50
    ): LengthAwarePaginator {
        $pageSize = min(max($pageSize, 1), 100);
        $query = AcademicTrack::where('program_id', $programId)
            ->orderBy('code')
            ->orderBy('id');

        if ($active !== null) {
            $query->where('is_active', $active);
        }

        return $query->paginate($pageSize, ['*'], 'page', $page);
    }

    public function findById(int $id): ?AcademicTrack
    {
        return AcademicTrack::with('program')->find($id);
    }

    public function findByProgramAndCode(int $programId, string $code, ?int $excludeId = null): ?AcademicTrack
    {
        $normalized = strtoupper(trim($code));
        $query = AcademicTrack::where('program_id', $programId)
            ->whereRaw('UPPER(code) = ?', [$normalized]);

        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->first();
    }

    public function hasOfferings(int $trackId): bool
    {
        return AcademicTrack::where('id', $trackId)
            ->whereHas('offerings')
            ->exists();
    }

    public function hasActiveOfferings(int $trackId): bool
    {
        return AcademicTrack::where('id', $trackId)
            ->whereHas('offerings', fn ($q) => $q->where('is_active', true))
            ->exists();
    }

    public function create(array $attributes): AcademicTrack
    {
        return AcademicTrack::create($attributes);
    }

    public function update(AcademicTrack $track, array $attributes): AcademicTrack
    {
        $track->update($attributes);

        return $track->fresh();
    }
}

