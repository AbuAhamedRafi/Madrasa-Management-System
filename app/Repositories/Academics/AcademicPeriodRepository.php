<?php

namespace App\Repositories\Academics;

use App\Models\AcademicPeriod;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class AcademicPeriodRepository
{
    public function paginate(
        ?int $programId = null,
        ?int $cohortId = null,
        ?string $state = null,
        int $page = 1,
        int $pageSize = 20
    ): LengthAwarePaginator {
        $pageSize = min(max($pageSize, 1), 100);
        $query = AcademicPeriod::with(['program', 'cohort'])
            ->withCount('offerings')
            ->orderByDesc('start_date')
            ->orderBy('study_year_number')
            ->orderBy('id');

        if ($programId !== null) {
            $query->where('program_id', $programId);
        }

        if ($cohortId !== null) {
            $query->where('cohort_id', $cohortId);
        }

        if ($state !== null && trim($state) !== '' && strtoupper(trim($state)) !== 'ALL') {
            $query->where('state', strtoupper(trim($state)));
        }

        return $query->paginate($pageSize, ['*'], 'page', $page);
    }

    public function findById(int $id): ?AcademicPeriod
    {
        return AcademicPeriod::with(['program', 'cohort', 'offerings.programClass', 'offerings.track', 'offerings.sections'])
            ->find($id);
    }

    public function findCalendarPeriod(int $programId, int $calendarYear, ?int $excludeId = null): ?AcademicPeriod
    {
        $query = AcademicPeriod::where('program_id', $programId)
            ->whereNull('cohort_id')
            ->where('calendar_year', $calendarYear);

        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->first();
    }

    public function findSessionPeriod(int $cohortId, int $studyYearNumber, ?int $excludeId = null): ?AcademicPeriod
    {
        $query = AcademicPeriod::where('cohort_id', $cohortId)
            ->where('study_year_number', $studyYearNumber);

        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->first();
    }

    public function getPeriodsForCohort(int $cohortId, ?int $excludeId = null): Collection
    {
        $query = AcademicPeriod::where('cohort_id', $cohortId)
            ->orderBy('study_year_number');

        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->get();
    }

    public function hasOverlappingCalendarPeriod(int $programId, string $startDate, string $endDate, ?int $excludeId = null): bool
    {
        $query = AcademicPeriod::where('program_id', $programId)
            ->whereNull('cohort_id')
            ->where('start_date', '<=', $endDate)
            ->where('end_date', '>=', $startDate);

        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->exists();
    }

    public function hasActiveOfferingWithSection(int $periodId): bool
    {
        return AcademicPeriod::where('id', $periodId)
            ->whereHas('offerings', function ($offeringQuery) {
                $offeringQuery->where('is_active', true)
                    ->whereHas('sections', fn ($secQuery) => $secQuery->where('is_active', true)->where('capacity', '>', 0));
            })
            ->exists();
    }

    public function create(array $attributes): AcademicPeriod
    {
        return AcademicPeriod::create($attributes)->fresh(['program', 'cohort']);
    }

    public function update(AcademicPeriod $period, array $attributes): AcademicPeriod
    {
        $period->update($attributes);

        return $period->fresh(['program', 'cohort']);
    }
}

