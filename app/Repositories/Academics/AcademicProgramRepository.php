<?php

namespace App\Repositories\Academics;

use App\Models\AcademicProgram;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class AcademicProgramRepository
{
    public function paginate(
        ?int $departmentId = null,
        ?string $scheme = null,
        ?bool $active = null,
        ?string $state = null,
        ?string $search = null,
        int $page = 1,
        int $pageSize = 20
    ): LengthAwarePaginator {
        $pageSize = min(max($pageSize, 1), 100);
        $query = AcademicProgram::with(['department', 'educationLevel.authority'])
            ->withCount(['classes', 'tracks', 'cohorts', 'periods'])
            ->orderBy('code')
            ->orderBy('id');

        if ($departmentId !== null) {
            $query->where('department_id', $departmentId);
        }

        if ($scheme !== null && trim($scheme) !== '') {
            $query->where('scheme', strtoupper(trim($scheme)));
        }

        if ($state !== null && trim($state) !== '') {
            $query->where('state', strtoupper(trim($state)));
        } elseif ($active !== null) {
            $query->where('state', $active ? 'ACTIVE' : 'INACTIVE');
        }

        if ($search !== null && trim($search) !== '') {
            $term = '%' . strtolower(trim($search)) . '%';
            $query->where(function (Builder $q) use ($term) {
                $q->whereRaw('LOWER(code) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(name_en) LIKE ?', [$term])
                    ->orWhere('name_bn', 'LIKE', $term);
            });
        }

        return $query->paginate($pageSize, ['*'], 'page', $page);
    }

    public function findById(int $id): ?AcademicProgram
    {
        return AcademicProgram::with(['department', 'educationLevel.authority'])
            ->withCount(['classes', 'tracks', 'cohorts', 'periods'])
            ->find($id);
    }

    public function findStructureById(int $id): ?AcademicProgram
    {
        return AcademicProgram::with([
            'department',
            'educationLevel.authority',
            'classes',
            'tracks',
            'cohorts',
            'periods',
        ])->find($id);
    }

    public function findByNormalizedCode(string $code, ?int $excludeId = null): ?AcademicProgram
    {
        $normalized = strtoupper(trim($code));
        $query = AcademicProgram::whereRaw('UPPER(code) = ?', [$normalized]);

        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->first();
    }

    public function hasDependentCohortsOrPeriods(int $programId): bool
    {
        return AcademicProgram::where('id', $programId)
            ->where(function (Builder $q) {
                $q->whereHas('cohorts')
                    ->orWhereHas('periods');
            })
            ->exists();
    }

    public function hasOpenPeriodsOrActiveCohorts(int $programId): bool
    {
        return AcademicProgram::where('id', $programId)
            ->where(function (Builder $q) {
                $q->whereHas('periods', fn ($p) => $p->where('state', 'OPEN'))
                    ->orWhereHas('cohorts', fn ($c) => $c->where('status', 'ACTIVE'));
            })
            ->exists();
    }

    public function create(array $attributes): AcademicProgram
    {
        return AcademicProgram::create($attributes)->fresh(['department', 'educationLevel.authority']);
    }

    public function update(AcademicProgram $program, array $attributes): AcademicProgram
    {
        $program->update($attributes);

        return $program->fresh(['department', 'educationLevel.authority']);
    }
}

