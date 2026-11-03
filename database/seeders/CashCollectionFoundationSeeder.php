<?php

namespace Database\Seeders;

use App\Enums\CashierShiftStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentSlipStatus;
use App\Enums\PaymentStatus;
use App\Enums\ReceiptStatus;
use App\Models\AcademicPeriod;
use App\Models\CashierShift;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentNotification;
use App\Models\PaymentReceipt;
use App\Models\PaymentSlip;
use App\Models\Student;
use App\Models\User;
use App\Services\Payments\PaymentSequenceService;
use App\Services\Payments\SlipVerificationTokenService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CashCollectionFoundationSeeder extends Seeder
{
    public function run(): void
    {
        $sequenceService = app(PaymentSequenceService::class);
        $tokenService = app(SlipVerificationTokenService::class);

        $accountsUser = User::where('email', 'like', '%accounts%')->first()
            ?? User::first();
        $adminUser = User::where('email', 'like', '%admin%')->first()
            ?? $accountsUser;

        $period = AcademicPeriod::first();
        if (!$period || !$accountsUser) {
            return;
        }

        // 1. Seed an active CashierShift
        $existingShift = CashierShift::where('status', CashierShiftStatus::OPEN->value)->first();
        if (!$existingShift) {
            $shiftNumber = $sequenceService->nextNumber('CS', 'cashier_shifts', 'shift_number');
            $shift = CashierShift::create([
                'shift_number' => $shiftNumber,
                'cashier_user_id' => $accountsUser->id,
                'counter_code' => 'COUNTER_01',
                'status' => CashierShiftStatus::OPEN,
                'opened_at' => now(),
                'opening_float_amount' => 500.00,
                'expected_cash_amount' => 500.00,
            ]);
        } else {
            $shift = $existingShift;
        }

        // 2. Find an ISSUED invoice with positive balance
        $invoice = Invoice::with(['items', 'student'])
            ->where('status', InvoiceStatus::ISSUED->value)
            ->where('balance_amount', '>', 0)
            ->first();

        if ($invoice) {
            // Seed a sample PaymentSlip
            $slipNumber = $sequenceService->nextNumber('PS', 'payment_slips', 'slip_number');
            $slipToken = $tokenService->generateToken('slip', 0, $slipNumber);

            $slip = PaymentSlip::firstOrCreate(
                ['slip_number' => $slipNumber],
                [
                    'invoice_id' => $invoice->id,
                    'student_id' => $invoice->student_id,
                    'enrollment_id' => $invoice->enrollment_id,
                    'academic_period_id' => $invoice->academic_period_id,
                    'status' => PaymentSlipStatus::UNPAID,
                    'slip_amount' => $invoice->balance_amount,
                    'collected_amount' => 0.00,
                    'remaining_amount' => $invoice->balance_amount,
                    'expires_at' => now()->addDays(7),
                    'verification_token_hash' => $slipToken['hash'],
                    'verification_token_encrypted' => $slipToken['encrypted'],
                    'snapshot_payload' => [
                        'invoice' => ['invoice_number' => $invoice->invoice_number],
                        'student' => ['name_bn' => $invoice->student?->name_bn],
                    ],
                    'print_count' => 1,
                    'issued_by' => $accountsUser->id,
                    'issued_at' => now(),
                ]
            );
        }
    }
}
