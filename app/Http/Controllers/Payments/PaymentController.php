<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('payments.read');

        $query = Payment::with(['student', 'invoice', 'paymentSlip', 'receipt', 'collector', 'cashierShift'])
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

        if ($request->filled('cashier_shift_id')) {
            $query->where('cashier_shift_id', $request->query('cashier_shift_id'));
        }

        if ($request->filled('search')) {
            $search = $request->query('search');
            $query->where(function ($q) use ($search) {
                $q->where('payment_reference', 'ILIKE', "%{$search}%")
                    ->orWhereHas('student', function ($sq) use ($search) {
                        $sq->where('student_code', 'ILIKE', "%{$search}%")
                            ->orWhere('name_bn', 'ILIKE', "%{$search}%")
                            ->orWhere('name_en', 'ILIKE', "%{$search}%");
                    });
            });
        }

        $perPage = min(100, max(10, (int) $request->query('per_page', 15)));
        $payments = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $payments->items(),
            'meta' => [
                'current_page' => $payments->currentPage(),
                'last_page' => $payments->lastPage(),
                'per_page' => $payments->perPage(),
                'total' => $payments->total(),
            ],
        ]);
    }

    public function show(Payment $payment): JsonResponse
    {
        $this->authorize('payments.read');

        return response()->json([
            'success' => true,
            'data' => $payment->load([
                'student',
                'invoice.items',
                'paymentSlip',
                'receipt',
                'allocations.invoiceItem',
                'collector',
                'cashierShift',
                'reversal.approver',
                'notifications',
            ]),
        ]);
    }
}

