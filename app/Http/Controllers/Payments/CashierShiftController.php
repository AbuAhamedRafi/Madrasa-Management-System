<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Models\CashierShift;
use App\Services\Payments\CashierShiftService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CashierShiftController extends Controller
{
    public function __construct(
        protected CashierShiftService $shiftService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('payments.cashier.read');

        $query = CashierShift::with(['cashier', 'reconciler'])->orderByDesc('id');

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('cashier_user_id')) {
            $query->where('cashier_user_id', $request->query('cashier_user_id'));
        }

        $perPage = min(100, max(10, (int) $request->query('per_page', 15)));
        $shifts = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $shifts->items(),
            'meta' => [
                'current_page' => $shifts->currentPage(),
                'last_page' => $shifts->lastPage(),
                'per_page' => $shifts->perPage(),
                'total' => $shifts->total(),
            ],
        ]);
    }

    public function open(Request $request): JsonResponse
    {
        $this->authorize('payments.cashier.reconcile');

        $validated = $request->validate([
            'opening_float_amount' => 'nullable|numeric|min:0.00',
            'counter_code' => 'nullable|string|max:64',
        ]);

        $shift = $this->shiftService->openShift($validated, $request->user());

        return response()->json([
            'success' => true,
            'message' => 'Cashier shift opened successfully.',
            'data' => $shift->load('cashier'),
        ], 201);
    }

    public function show(CashierShift $shift): JsonResponse
    {
        $this->authorize('payments.cashier.read');

        return response()->json([
            'success' => true,
            'data' => $shift->load(['cashier', 'reconciler', 'events.actor', 'payments']),
        ]);
    }

    public function close(CashierShift $shift, Request $request): JsonResponse
    {
        $this->authorize('payments.cashier.reconcile');

        $validated = $request->validate([
            'counted_cash_amount' => 'required|numeric|min:0.00',
            'denomination_breakdown' => 'nullable|array',
            'closing_notes' => 'nullable|string|max:500',
            'variance_reason' => 'nullable|string|max:500',
        ]);

        $closedShift = $this->shiftService->closeShift($shift, $validated, $request->user());

        return response()->json([
            'success' => true,
            'message' => 'Cashier shift closed successfully.',
            'data' => $closedShift->load(['cashier', 'reconciler']),
        ]);
    }

    public function reconcile(CashierShift $shift, Request $request): JsonResponse
    {
        $this->authorize('payments.cashier.approve');

        $validated = $request->validate([
            'reconciliation_notes' => 'nullable|string|max:500',
        ]);

        $reconciledShift = $this->shiftService->reconcileShift($shift, $validated, $request->user());

        return response()->json([
            'success' => true,
            'message' => 'Cashier shift reconciled successfully.',
            'data' => $reconciledShift->load(['cashier', 'reconciler']),
        ]);
    }
}

