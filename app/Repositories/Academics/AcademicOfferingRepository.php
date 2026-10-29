<?php

namespace App\Repositories\Academics;

use App\Models\AcademicOffering;
use App\Models\OfferingSection;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class AcademicOfferingRepository
{
    public function paginateByPeriod(
        int $periodId,
        ?int $trackId = null,
        ?bool $active = null,
        int $page = 1,
        int $pageSize = 50
    ): LengthAwarePaginator {
        $pageSize = min(max($pageSize, 1), 100);
        $query = AcademicOffering::with(['period.program', 'programClass', 'track', 'sections'])
            ->where('academic_period_id', $periodId)
            ->orderBy('program_class_id')
            ->orderBy('academic_track_id')
            ->orderBy('id');

        if ($trackId !== null) {
            $query->where('academic_track_id', $trackId);
        }

        if ($active !== null) {
            $query->where('is_active', $active);
        }

        return $query->paginate($pageSize, ['*'], 'page', $page);
    }

    public function findById(int $id): ?AcademicOffering
    {
        return AcademicOffering::with(['period.program', 'programClass', 'track', 'sections'])->find($id);
    }

    public function findIdentity(int $periodId, int $classId, ?int $trackId, ?int $excludeId = null): ?AcademicOffering
    {
        $query = AcademicOffering::where('academic_period_id', $periodId)
            ->where('program_class_id', $classId);

        if ($trackId === null) {
            $query->whereNull('academic_track_id');
        } else {
            $query->where('academic_track_id', $trackId);
        }

        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->first();
    }

    public function create(array $attributes): AcademicOffering
    {
        return AcademicOffering::create($attributes)->fresh(['period.program', 'programClass', 'track', 'sections']);
    }

    public function update(AcademicOffering $offering, array $attributes): AcademicOffering
    {
        $offering->update($attributes);

        return $offering->fresh(['period.program', 'programClass', 'track', 'sections']);
    }

    public function findSectionById(int $id): ?OfferingSection
    {
        return OfferingSection::with(['offering.period', 'offering.programClass', 'offering.track'])->find($id);
    }

    public function findSectionByOfferingAndCode(int $offeringId, string $code, ?int $excludeId = null): ?OfferingSection
    {
        $normalized = strtoupper(trim($code));
        $query = OfferingSection::where('academic_offering_id', $offeringId)
            ->whereRaw('UPPER(code) = ?', [$normalized]);

        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->first();
    }

    public function createSection(array $attributes): OfferingSection
    {
        return OfferingSection::create($attributes)->fresh(['offering']);
    }

    public function updateSection(OfferingSection $section, array $attributes): OfferingSection
    {
        $section->update($attributes);

        return $section->fresh(['offering']);
    }
}

