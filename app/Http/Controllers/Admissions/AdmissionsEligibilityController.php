<?php

namespace App\Http\Controllers\Admissions;

use App\Http\Controllers\Controller;
use App\Services\Admissions\AdmissionEligibilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdmissionsEligibilityController extends Controller
{
    public function __construct(
        protected AdmissionEligibilityService $eligibilityService
    ) {}

    public function show(Request $request, int $studentId): JsonResponse
    {
        $programId = (int) $request->query('academic_program_id');
        $periodId = $request->filled('academic_period_id') ? (int) $request->query('academic_period_id') : null;
        $cohortId = $request->filled('academic_cohort_id') ? (int) $request->query('academic_cohort_id') : null;

        $result = $this->eligibilityService->checkEligibility($studentId, $programId, $periodId, $cohortId);

        return response()->json(['data' => $result]);
    }
}
