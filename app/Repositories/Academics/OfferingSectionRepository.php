<?php

namespace App\Repositories\Academics;

use App\Models\OfferingSection;

class OfferingSectionRepository
{
    public function findById(int $id): ?OfferingSection
    {
        return OfferingSection::with(['offering.period', 'offering.programClass', 'offering.track'])->find($id);
    }

    public function findByOfferingAndCode(int $offeringId, string $code, ?int $excludeId = null): ?OfferingSection
    {
        $normalized = strtoupper(trim($code));
        $query = OfferingSection::where('academic_offering_id', $offeringId)
            ->whereRaw('UPPER(code) = ?', [$normalized]);

        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->first();
    }

    public function create(array $attributes): OfferingSection
    {
        return OfferingSection::create($attributes)->fresh(['offering']);
    }

    public function update(OfferingSection $section, array $attributes): OfferingSection
    {
        $section->update($attributes);

        return $section->fresh(['offering']);
    }
}
