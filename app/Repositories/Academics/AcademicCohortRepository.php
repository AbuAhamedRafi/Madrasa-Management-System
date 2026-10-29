<?php

namespace App\Repositories\Academics;

use App\Models\AcademicCohort;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class AcademicCohortRepository
{
    public function paginate(
        int $programId,
        ?string $status = null,
        ?int $intakeYear = null,
        int $page = 1,
        int $pageSize = 20
    ): LengthAwarePaginator {
        $pageSize = min(max($pageSize, 1), 100);
        $query = AcademicCohort::with('program')
            ->withCount('periods')
            ->where('program_id', $programId)
            ->orderByDesc('intake_year')
            ->orderBy('label')
            ->orderBy('id');

        if ($status !== null && trim($status) !== '' && strtoupper(trim($status)) !== 'ALL') {
            $query->where('status', strtoupper(trim($status)));
        }

        if ($intakeYear !== null) {
            $query->where('intake_year', $intakeYear);
        }

        return $query->paginate($pageSize, ['*'], 'page', $page);
    }

    public function findById(int $id): ?AcademicCohort
    {
        return AcademicCohort::with(['program', 'periods'])->find($id);
    }

    public function findByProgramAndLabel(int $programId, string $label, ?int $excludeId = null): ?AcademicCohort
    {
        $normalized = strtoupper(trim($label));
        $query = AcademicCohort::where('program_id', $programId)
            ->whereRaw('UPPER(label) = ?', [$normalized]);

        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->first();
    }

    public function hasOpenPeriods(int $cohortId): bool
    {
        return AcademicCohort::where('id', $cohortId)
            ->whereHas('periods', fn ($q) => $q->where('state', 'OPEN'))
            ->exists();
    }

    public function create(array $attributes): AcademicCohort
    {
        return AcademicCohort::create($attributes)->fresh(['program']);
    }

    public function update(AcademicCohort $cohort, array $attributes): AcademicCohort
    {
        $cohort->update($attributes);

        return $cohort->fresh(['program']);
    }
}

