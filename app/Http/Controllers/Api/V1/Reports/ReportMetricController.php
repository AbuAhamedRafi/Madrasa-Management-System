<?php

namespace App\Http\Controllers\Api\V1\Reports;

use App\Http\Controllers\Controller;
use App\Services\Reports\MetricDefinitionRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportMetricController extends Controller
{
    public function __construct(
        protected MetricDefinitionRegistry $registry
    ) {}

    /**
     * List permitted KPI definitions, units, formulas, and dimensions (F01).
     */
    public function index(Request $request): JsonResponse
    {
        $metrics = $this->registry->getDefinitions($request->user());

        return response()->json([
            'data' => $metrics,
        ]);
    }

    /**
     * Inspect single metric definition (F01).
     */
    public function show(Request $request, string $metricCode): JsonResponse
    {
        $metric = $this->registry->getDefinition($metricCode, $request->user());

        return response()->json([
            'data' => $metric,
        ]);
    }
}
