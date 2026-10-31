<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Services\Billing\BillingLedgerReadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BillingDashboardController extends Controller
{
    public function __construct(
        private readonly BillingLedgerReadService $ledgerReadService
    ) {}

    /**
     * GET /api/v1/billing/summary
     */
    public function summary(Request $request): JsonResponse
    {
        $summary = $this->ledgerReadService->getBillingSummary($request->all());

        return response()->json([
            'data' => $summary,
        ]);
    }
}
