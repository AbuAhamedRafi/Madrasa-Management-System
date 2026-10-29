<?php

namespace App\Repositories\Academics;

use App\Models\EducationLevel;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class EducationLevelRepository
{
    public function paginate(
        ?int $authorityId = null,
        ?bool $active = null,
        int $page = 1,
        int $pageSize = 20
    ): LengthAwarePaginator {
        $pageSize = min(max($pageSize, 1), 100);
        $query = EducationLevel::with('authority')
            ->orderBy('sort_order')
            ->orderBy('code')
            ->orderBy('id');

        if ($authorityId !== null) {
            $query->where('authority_id', $authorityId);
        }

        if ($active !== null) {
            $query->where('is_active', $active);
        }

        return $query->paginate($pageSize, ['*'], 'page', $page);
    }

    public function findById(int $id): ?EducationLevel
    {
        return EducationLevel::with('authority')->find($id);
    }

    public function findByAuthorityAndCode(int $authorityId, string $code, ?int $excludeId = null): ?EducationLevel
    {
        $normalized = strtoupper(trim($code));
        $query = EducationLevel::where('authority_id', $authorityId)
            ->whereRaw('UPPER(code) = ?', [$normalized]);

        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->first();
    }

    public function hasReferencedPrograms(int $levelId): bool
    {
        return EducationLevel::where('id', $levelId)
            ->whereHas('programs')
            ->exists();
    }

    public function hasActivePrograms(int $levelId): bool
    {
        return EducationLevel::where('id', $levelId)
            ->whereHas('programs', fn ($q) => $q->whereIn('state', ['DRAFT', 'ACTIVE']))
            ->exists();
    }

    public function create(array $attributes): EducationLevel
    {
        return EducationLevel::create($attributes)->fresh(['authority']);
    }

    public function update(EducationLevel $level, array $attributes): EducationLevel
    {
        $level->update($attributes);

        return $level->fresh(['authority']);
    }
}

