<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Services\Billing\BillingLedgerReadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentBillingLedgerController extends Controller
{
    public function __construct(
        private readonly BillingLedgerReadService $ledgerReadService
    ) {}

    /**
     * GET /api/v1/students/{id}/billing-ledger
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $ledger = $this->ledgerReadService->getStudentLedger($id, $request->all());

        return response()->json([
            'data' => $ledger,
        ]);
    }
}
