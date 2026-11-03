<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Models\Enrollment;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentSlip;
use App\Models\Student;
use App\Services\Payments\AdmissionPaymentClearanceService;
use Illuminate\Http\JsonResponse;

class StudentPaymentLedgerController extends Controller
{
    public function __construct(
        protected AdmissionPaymentClearanceService $admissionClearanceService
    ) {}

    public function show(Student $student): JsonResponse
    {
        $this->authorize('payments.read');

        $invoices = Invoice::with('items')
            ->where('student_id', $student->id)
            ->orderByDesc('id')
            ->get();

        $payments = Payment::with(['receipt', 'allocations.invoiceItem', 'cashierShift'])
            ->where('student_id', $student->id)
            ->orderByDesc('id')
            ->get();

        $slips = PaymentSlip::where('student_id', $student->id)
            ->orderByDesc('id')
            ->get();

        $totalBilled = $invoices->sum(fn($i) => (float) $i->total_amount);
        $totalPaid = $payments->where('status.value', 'POSTED')->sum(fn($p) => (float) $p->amount_received);
        $currentOutstanding = max(0.00, $invoices->sum(fn($i) => (float) $i->balance_amount));

        // Latest enrollment clearance
        $activeEnrollment = Enrollment::where('student_id', $student->id)->orderByDesc('id')->first();
        $clearance = $activeEnrollment ? $this->admissionClearanceService->getClearanceStatus($activeEnrollment->id) : null;

        return response()->json([
            'success' => true,
            'data' => [
                'student' => [
                    'id' => $student->id,
                    'student_code' => $student->student_code,
                    'name_bn' => $student->name_bn,
                    'name_en' => $student->name_en,
                ],
                'summary' => [
                    'total_billed' => number_format($totalBilled, 2, '.', ''),
                    'total_paid' => number_format($totalPaid, 2, '.', ''),
                    'current_outstanding' => number_format($currentOutstanding, 2, '.', ''),
                    'admission_clearance' => $clearance,
                ],
                'invoices' => $invoices,
                'payments' => $payments,
                'payment_slips' => $slips,
            ],
        ]);
    }
}

