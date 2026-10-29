<?php

namespace App\Http\Controllers\Academics;

use App\Http\Controllers\Controller;
use App\Services\Academics\AcademicQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AcademicOverviewController extends Controller
{
    public function __construct(
        protected AcademicQueryService $queryService
    ) {}

    public function summary(Request $request): JsonResponse
    {
        $programId = $request->filled('program_id') ? (int) $request->query('program_id') : null;

        return response()->json([
            'data' => $this->queryService->getSummary($programId),
        ]);
    }

    public function hierarchy(Request $request): JsonResponse
    {
        $authorityId = $request->filled('authority_id') ? (int) $request->query('authority_id') : null;

        return response()->json([
            'data' => $this->queryService->getHierarchy(
                $authorityId,
                $request->query('search')
            ),
        ]);
    }

    public function options(Request $request): JsonResponse
    {
        $programId = $request->filled('program_id') ? (int) $request->query('program_id') : null;
        $periodId = $request->filled('academic_period_id') ? (int) $request->query('academic_period_id') : null;
        $activeOnly = $request->has('active_only')
            ? (bool) filter_var($request->query('active_only'), FILTER_VALIDATE_BOOLEAN)
            : false;

        return response()->json([
            'data' => $this->queryService->getOptions($programId, $periodId, $activeOnly),
        ]);
    }
}

