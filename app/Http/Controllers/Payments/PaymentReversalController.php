<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\PaymentReversalRequest;
use App\Services\Payments\PaymentReversalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentReversalController extends Controller
{
    public function __construct(
        protected PaymentReversalService $reversalService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('payments.reversals.read');

        $query = PaymentReversalRequest::with(['payment.receipt', 'invoice', 'student', 'requester', 'reviewer', 'reversal'])
            ->orderByDesc('id');

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        $perPage = min(100, max(10, (int) $request->query('per_page', 15)));
        $requests = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $requests->items(),
            'meta' => [
                'current_page' => $requests->currentPage(),
                'last_page' => $requests->lastPage(),
                'per_page' => $requests->perPage(),
                'total' => $requests->total(),
            ],
        ]);
    }

    public function storeRequest(Payment $payment, Request $request): JsonResponse
    {
        $this->authorize('payments.reversals.request');

        $validated = $request->validate([
            'reason_category' => 'nullable|string|in:DUPLICATE_POSTING,WRONG_STUDENT_OR_INVOICE,CASH_COUNT_ERROR,DATA_ENTRY_ERROR,OTHER',
            'reason_details' => 'required|string|min:10|max:1000',
        ]);

        $reversalReq = $this->reversalService->requestReversal($payment, $validated, $request->user());

        return response()->json([
            'success' => true,
            'message' => 'Payment reversal request submitted for supervisor approval.',
            'data' => $reversalReq->load(['payment.receipt', 'requester']),
        ], 201);
    }

    public function show(PaymentReversalRequest $reversalRequest): JsonResponse
    {
        $this->authorize('payments.reversals.read');

        return response()->json([
            'success' => true,
            'data' => $reversalRequest->load(['payment.receipt', 'invoice', 'student', 'requester', 'reviewer', 'reversal.approver']),
        ]);
    }

    public function approve(PaymentReversalRequest $reversalRequest, Request $request): JsonResponse
    {
        $this->authorize('payments.reversals.approve');

        $validated = $request->validate([
            'notes' => 'nullable|string|max:500',
        ]);

        $reversal = $this->reversalService->approveReversal($reversalRequest, $validated, $request->user());

        return response()->json([
            'success' => true,
            'message' => 'Payment reversal approved and compensating entries recorded.',
            'data' => $reversal->load(['payment', 'approver']),
        ]);
    }

    public function reject(PaymentReversalRequest $reversalRequest, Request $request): JsonResponse
    {
        $this->authorize('payments.reversals.approve');

        $validated = $request->validate([
            'notes' => 'nullable|string|max:500',
        ]);

        $rejected = $this->reversalService->rejectReversal($reversalRequest, $validated, $request->user());

        return response()->json([
            'success' => true,
            'message' => 'Payment reversal request rejected.',
            'data' => $rejected->load(['payment', 'reviewer']),
        ]);
    }
}

