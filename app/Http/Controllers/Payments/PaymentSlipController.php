<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\PaymentSlip;
use App\Services\Payments\PaymentSlipService;
use App\Services\Payments\SlipVerificationTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PaymentSlipController extends Controller
{
    public function __construct(
        protected PaymentSlipService $slipService,
        protected SlipVerificationTokenService $tokenService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = PaymentSlip::with(['student', 'invoice', 'academicPeriod', 'issuer'])
            ->orderByDesc('id');

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('student_id')) {
            $query->where('student_id', $request->query('student_id'));
        }

        if ($request->filled('invoice_id')) {
            $query->where('invoice_id', $request->query('invoice_id'));
        }

        if ($request->filled('search')) {
            $search = $request->query('search');
            $query->where(function ($q) use ($search) {
                $q->where('slip_number', 'ILIKE', "%{$search}%")
                    ->orWhereHas('student', function ($sq) use ($search) {
                        $sq->where('student_code', 'ILIKE', "%{$search}%")
                            ->orWhere('name_bn', 'ILIKE', "%{$search}%")
                            ->orWhere('name_en', 'ILIKE', "%{$search}%");
                    });
            });
        }

        $perPage = min(100, max(10, (int) $request->query('per_page', 15)));
        $slips = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $slips->items(),
            'meta' => [
                'current_page' => $slips->currentPage(),
                'last_page' => $slips->lastPage(),
                'per_page' => $slips->perPage(),
                'total' => $slips->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'invoice_id' => 'required|integer|exists:invoices,id',
            'slip_amount' => 'nullable|numeric|min:0.01',
            'expires_at' => 'nullable|date',
            'notes' => 'nullable|string|max:500',
            'supersede_existing' => 'nullable|boolean',
        ]);

        $slip = $this->slipService->issueSlip($validated, $request->user());

        return response()->json([
            'success' => true,
            'message' => 'Payment slip issued successfully.',
            'data' => $slip->load(['student', 'invoice', 'academicPeriod']),
        ], 201);
    }

    public function show(PaymentSlip $slip): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $slip->load(['student', 'invoice.items', 'academicPeriod', 'issuer', 'payments.receipt']),
        ]);
    }

    public function void(PaymentSlip $slip, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'reason' => 'required|string|min:5|max:500',
        ]);

        $voidedSlip = $this->slipService->voidSlip($slip, $validated['reason'], $request->user());

        return response()->json([
            'success' => true,
            'message' => 'Payment slip voided successfully.',
            'data' => $voidedSlip,
        ]);
    }

    public function lookupByNumber(string $slipNumber): JsonResponse
    {
        $slip = PaymentSlip::with(['student', 'invoice.items', 'academicPeriod'])
            ->where('slip_number', $slipNumber)
            ->first();

        if (!$slip) {
            return response()->json([
                'error' => [
                    'code' => 'SLIP_NOT_FOUND',
                    'message' => 'Payment slip not found.',
                ],
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $slip,
        ]);
    }

    public function printPayload(PaymentSlip $slip, Request $request): JsonResponse
    {
        $this->slipService->recordPrint($slip, $request->user());

        return response()->json([
            'success' => true,
            'data' => [
                'slip_number' => $slip->slip_number,
                'status' => $slip->status->value,
                'slip_amount' => (string) $slip->slip_amount,
                'collected_amount' => (string) $slip->collected_amount,
                'remaining_amount' => (string) $slip->remaining_amount,
                'expires_at' => $slip->expires_at?->toDateString(),
                'print_count' => $slip->print_count,
                'last_printed_at' => $slip->last_printed_at?->toIso8601String(),
                'verification_token_hash' => $slip->verification_token_hash,
                'snapshot' => $slip->snapshot_payload,
            ],
        ]);
    }
}
