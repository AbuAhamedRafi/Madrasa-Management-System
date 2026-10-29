<?php

namespace App\Repositories\Academics;

use App\Models\Department;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class DepartmentRepository
{
    public function paginate(
        ?string $search = null,
        ?bool $active = null,
        int $page = 1,
        int $pageSize = 20
    ): LengthAwarePaginator {
        $pageSize = min(max($pageSize, 1), 100);
        $query = Department::withCount('programs')
            ->orderBy('sort_order')
            ->orderBy('code')
            ->orderBy('id');

        if ($search !== null && trim($search) !== '') {
            $term = '%' . strtolower(trim($search)) . '%';
            $query->where(function (Builder $q) use ($term) {
                $q->whereRaw('LOWER(code) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(name_en) LIKE ?', [$term])
                    ->orWhere('name_bn', 'LIKE', $term);
            });
        }

        if ($active !== null) {
            $query->where('is_active', $active);
        }

        return $query->paginate($pageSize, ['*'], 'page', $page);
    }

    public function findById(int $id): ?Department
    {
        return Department::with('programs.educationLevel')->find($id);
    }

    public function findByNormalizedCode(string $code, ?int $excludeId = null): ?Department
    {
        $normalized = strtoupper(trim($code));
        $query = Department::whereRaw('UPPER(code) = ?', [$normalized]);

        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->first();
    }

    public function hasActiveProgramsOrOpenPeriods(int $departmentId): bool
    {
        return Department::where('id', $departmentId)
            ->where(function (Builder $query) {
                $query->whereHas('programs', fn ($q) => $q->where('state', 'ACTIVE'))
                    ->orWhereHas('programs.periods', fn ($q) => $q->where('state', 'OPEN'));
            })
            ->exists();
    }

    public function create(array $attributes): Department
    {
        return Department::create($attributes);
    }

    public function update(Department $department, array $attributes): Department
    {
        $department->update($attributes);

        return $department->fresh(['programs']);
    }
}

