<?php

namespace App\Http\Controllers\Admissions;

use App\Http\Controllers\Controller;
use App\Models\OfferingSection;
use App\Services\Admissions\SeatAllocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SectionCapacityController extends Controller
{
    public function __construct(
        protected SeatAllocationService $seatAllocationService
    ) {}

    public function show(int $sectionId): JsonResponse
    {
        $capacity = $this->seatAllocationService->checkCapacity($sectionId);

        return response()->json(['data' => $capacity]);
    }

    public function alternatives(int $offeringId): JsonResponse
    {
        $sections = OfferingSection::where('academic_offering_id', $offeringId)
            ->where('is_active', true)
            ->get();

        $data = $sections->map(function ($section) {
            return $this->seatAllocationService->checkCapacity($section->id);
        });

        return response()->json(['data' => $data]);
    }

    public function batchCheck(Request $request): JsonResponse
    {
        $request->validate([
            'section_ids' => ['required', 'array'],
            'section_ids.*' => ['integer', 'exists:offering_sections,id'],
        ]);

        $results = [];
        foreach ($request->input('section_ids') as $id) {
            $results[] = $this->seatAllocationService->checkCapacity((int) $id);
        }

        return response()->json(['data' => $results]);
    }
}

