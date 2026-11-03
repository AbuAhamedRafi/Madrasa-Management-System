<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Models\CashierShift;
use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\PaymentReceipt;
use App\Models\PaymentReversalRequest;
use App\Models\PaymentSlip;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;

class PaymentDashboardController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('payments.read');

        $today = Carbon::today();

        $todayCollections = Payment::where('status', 'POSTED')
            ->whereDate('collected_at', $today)
            ->sum('amount_received');

        $todayReceiptCount = PaymentReceipt::whereDate('issued_at', $today)->count();

        $unpaidSlipsCount = PaymentSlip::whereIn('status', ['UNPAID', 'PARTIALLY_COLLECTED'])->count();

        $activeShiftsCount = CashierShift::where('status', 'OPEN')->count();

        $pendingReversalsCount = PaymentReversalRequest::where('status', 'PENDING')->count();

        $pendingClearancesCount = Enrollment::where('status', 'PENDING_PAYMENT')->count();

        $recentPayments = Payment::with(['student', 'receipt', 'collector'])
            ->orderByDesc('id')
            ->limit(5)
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'metrics' => [
                    'today_collections' => number_format((float) $todayCollections, 2, '.', ''),
                    'today_receipt_count' => $todayReceiptCount,
                    'unpaid_slips_count' => $unpaidSlipsCount,
                    'active_shifts_count' => $activeShiftsCount,
                    'pending_reversals_count' => $pendingReversalsCount,
                    'pending_clearances_count' => $pendingClearancesCount,
                ],
                'recent_payments' => $recentPayments,
            ],
        ]);
    }
}

