<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\PaymentSlip;
use App\Services\Payments\CashCollectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CashCollectionController extends Controller
{
    public function __construct(
        protected CashCollectionService $collectionService
    ) {}

    public function collectBySlip(PaymentSlip $slip, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'amount_received' => 'nullable|numeric|min:0.01',
            'cash_tendered_amount' => 'nullable|numeric|min:0.01',
            'remarks' => 'nullable|string|max:500',
            'idempotency_key' => 'nullable|string|max:128',
        ]);

        $idempotencyKey = $request->header('Idempotency-Key') ?: ($validated['idempotency_key'] ?? null);

        $result = $this->collectionService->collectCash($slip, $validated, $request->user(), $idempotencyKey);

        return response()->json($result, 201);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'payment_slip_id' => 'required|integer|exists:payment_slips,id',
            'amount_received' => 'nullable|numeric|min:0.01',
            'cash_tendered_amount' => 'nullable|numeric|min:0.01',
            'remarks' => 'nullable|string|max:500',
            'idempotency_key' => 'nullable|string|max:128',
        ]);

        $slip = PaymentSlip::findOrFail($validated['payment_slip_id']);
        $idempotencyKey = $request->header('Idempotency-Key') ?: ($validated['idempotency_key'] ?? null);

        $result = $this->collectionService->collectCash($slip, $validated, $request->user(), $idempotencyKey);

        return response()->json($result, 201);
    }
}
