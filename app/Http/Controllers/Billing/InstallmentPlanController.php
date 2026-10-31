<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Services\Billing\InstallmentPlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InstallmentPlanController extends Controller
{
    public function __construct(
        private readonly InstallmentPlanService $installmentPlanService
    ) {}

    /**
     * GET /api/v1/invoices/{id}/installment-plan
     */
    public function show(int $id): JsonResponse
    {
        $plan = $this->installmentPlanService->getPlan($id);

        return response()->json([
            'data' => $plan,
        ]);
    }

    /**
     * PUT /api/v1/invoices/{id}/installment-plan
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $actorId = (int) $request->user()->id;
        $plan = $this->installmentPlanService->replacePlan($id, $request->all(), $actorId);

        return response()->json([
            'message' => 'Installment schedule saved successfully.',
            'data' => $plan,
        ]);
    }
}

