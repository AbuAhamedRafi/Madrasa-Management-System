<?php

namespace App\Repositories\Academics;

use App\Models\EducationAuthority;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class AuthorityRepository
{
    public function paginate(?bool $active = null, int $page = 1, int $pageSize = 20): LengthAwarePaginator
    {
        $pageSize = min(max($pageSize, 1), 100);
        $query = EducationAuthority::withCount('levels')
            ->orderBy('sort_order')
            ->orderBy('code')
            ->orderBy('id');

        if ($active !== null) {
            $query->where('is_active', $active);
        }

        return $query->paginate($pageSize, ['*'], 'page', $page);
    }

    public function findById(int $id): ?EducationAuthority
    {
        return EducationAuthority::with('levels')->find($id);
    }

    public function findByNormalizedCode(string $code, ?int $excludeId = null): ?EducationAuthority
    {
        $normalized = strtoupper(trim($code));
        $query = EducationAuthority::whereRaw('UPPER(code) = ?', [$normalized]);

        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->first();
    }

    public function hasReferencedLevels(int $authorityId): bool
    {
        return EducationAuthority::where('id', $authorityId)
            ->whereHas('levels')
            ->exists();
    }

    public function hasActiveLevels(int $authorityId): bool
    {
        return EducationAuthority::where('id', $authorityId)
            ->whereHas('levels', fn ($q) => $q->where('is_active', true))
            ->exists();
    }

    public function create(array $attributes): EducationAuthority
    {
        return EducationAuthority::create($attributes);
    }

    public function update(EducationAuthority $authority, array $attributes): EducationAuthority
    {
        $authority->update($attributes);

        return $authority->fresh(['levels']);
    }
}

