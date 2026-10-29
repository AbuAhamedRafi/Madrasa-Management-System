<?php

namespace App\Repositories\Academics;

use App\Models\ProgramClass;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ProgramClassRepository
{
    public function paginate(
        int $programId,
        ?bool $active = null,
        int $page = 1,
        int $pageSize = 50
    ): LengthAwarePaginator {
        $pageSize = min(max($pageSize, 1), 100);
        $query = ProgramClass::where('program_id', $programId)
            ->orderBy('stage_number')
            ->orderBy('sort_order')
            ->orderBy('code')
            ->orderBy('id');

        if ($active !== null) {
            $query->where('is_active', $active);
        }

        return $query->paginate($pageSize, ['*'], 'page', $page);
    }

    public function findById(int $id): ?ProgramClass
    {
        return ProgramClass::with('program')->find($id);
    }

    public function findByProgramAndCode(int $programId, string $code, ?int $excludeId = null): ?ProgramClass
    {
        $normalized = strtoupper(trim($code));
        $query = ProgramClass::where('program_id', $programId)
            ->whereRaw('UPPER(code) = ?', [$normalized]);

        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->first();
    }

    public function findByProgramAndStage(int $programId, int $stageNumber, ?int $excludeId = null): ?ProgramClass
    {
        $query = ProgramClass::where('program_id', $programId)
            ->where('stage_number', $stageNumber);

        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->first();
    }

    public function hasOfferings(int $classId): bool
    {
        return ProgramClass::where('id', $classId)
            ->whereHas('offerings')
            ->exists();
    }

    public function hasActiveOfferings(int $classId): bool
    {
        return ProgramClass::where('id', $classId)
            ->whereHas('offerings', fn ($q) => $q->where('is_active', true))
            ->exists();
    }

    public function create(array $attributes): ProgramClass
    {
        return ProgramClass::create($attributes);
    }

    public function update(ProgramClass $programClass, array $attributes): ProgramClass
    {
        $programClass->update($attributes);

        return $programClass->fresh();
    }
}

