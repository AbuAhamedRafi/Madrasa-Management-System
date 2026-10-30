<?php

namespace App\Http\Controllers\Admissions;

use App\Http\Controllers\Controller;
use App\Models\AcademicProgram;
use Illuminate\Http\JsonResponse;

class AdmissionsCatalogController extends Controller
{
    public function show(int $programId): JsonResponse
    {
        $program = AcademicProgram::with([
            'periods' => function ($q) {
                $q->whereIn('state', ['OPEN', 'DRAFT'])->orderByDesc('start_date');
            },
            'periods.offerings.programClass',
            'periods.offerings.track',
            'periods.offerings.sections',
            'classes',
            'tracks',
            'cohorts' => function ($q) {
                $q->orderByDesc('intake_year');
            },
        ])->findOrFail($programId);

        return response()->json(['data' => $program]);
    }
}
