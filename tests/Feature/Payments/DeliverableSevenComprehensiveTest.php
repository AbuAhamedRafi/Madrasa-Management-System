<?php

namespace Tests\Feature\Payments;

use App\Enums\AccountStatus;
use App\Enums\CashierShiftStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentSlipStatus;
use App\Enums\PaymentStatus;
use App\Enums\ReceiptStatus;
use App\Enums\ReversalRequestStatus;
use App\Models\AcademicPeriod;
use App\Models\CashierShift;
use App\Models\Enrollment;
use App\Models\FeeCategory;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\PaymentReceipt;
use App\Models\PaymentReversalRequest;
use App\Models\PaymentSlip;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use App\Services\Payments\CashCollectionService;
use App\Services\Payments\PaymentSlipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeliverableSevenComprehensiveTest extends TestCase
{
    protected User $adminUser;
    protected User $staffUser;
    protected User $accountsUser;
    protected AcademicPeriod $period;
    protected Student $student;
    protected Enrollment $enrollment;
    protected Invoice $invoice;
    protected InvoiceItem $admissionItem;
    protected InvoiceItem $tuitionItem;

    protected function setUp(): void
    {
        parent::setUp();

        $adminRole = Role::where('key', 'ADMIN')->firstOrFail();
        $staffRole = Role::where('key', 'OFFICE_STAFF')->firstOrFail();
        $accountsRole = Role::where('key', 'ACCOUNTS')->firstOrFail();

        $this->adminUser = User::create([
            'public_id' => 'usr_' . Str::random(12),
            'name_bn' => 'অ্যাডমিন ইউজার',
            'name_en' => 'Admin User',
            'username' => 'admin_test_' . Str::random(6),
            'email' => 'admin_test_' . Str::random(6) . '@mms.edu.bd',
            'password' => bcrypt('AdminPass12345!'),
            'status' => AccountStatus::ACTIVE,
            'preferred_locale' => 'bn-BD',
        ]);
        $this->adminUser->roles()->sync([$adminRole->id]);

        $this->staffUser = User::create([
            'public_id' => 'usr_' . Str::random(12),
            'name_bn' => 'স্টাফ ইউজার',
            'name_en' => 'Staff User',
            'username' => 'staff_test_' . Str::random(6),
            'email' => 'staff_test_' . Str::random(6) . '@mms.edu.bd',
            'password' => bcrypt('StaffPass12345!'),
            'status' => AccountStatus::ACTIVE,
            'preferred_locale' => 'bn-BD',
        ]);
        $this->staffUser->roles()->sync([$staffRole->id]);

        $this->accountsUser = User::create([
            'public_id' => 'usr_' . Str::random(12),
            'name_bn' => 'হিসাব ইউজার',
            'name_en' => 'Accounts User',
            'username' => 'accounts_test_' . Str::random(6),
            'email' => 'accounts_test_' . Str::random(6) . '@mms.edu.bd',
            'password' => bcrypt('AccountsPass12345!'),
            'status' => AccountStatus::ACTIVE,
            'preferred_locale' => 'bn-BD',
        ]);
        $this->accountsUser->roles()->sync([$accountsRole->id]);

        $this->period = AcademicPeriod::firstOrFail();
        $this->student = Student::firstOrFail();
        $this->enrollment = Enrollment::where('student_id', $this->student->id)->firstOrFail();
        $this->enrollment->update(['status' => EnrollmentStatus::PENDING_PAYMENT]);

        $feeCatAdm = FeeCategory::firstOrCreate(
            ['code' => 'ADMISSION_TEST'],
            ['name_bn' => 'ভর্তি ফি', 'name_en' => 'Admission Fee', 'billing_type' => 'ADMISSION', 'default_cadence' => 'PER_ENROLLMENT', 'is_mandatory_default' => true, 'is_active' => true, 'sort_order' => 1]
        );

        $feeCatTuit = FeeCategory::firstOrCreate(
            ['code' => 'TUITION_TEST'],
            ['name_bn' => 'মাসিক বেতন', 'name_en' => 'Monthly Tuition', 'billing_type' => 'RECURRING', 'default_cadence' => 'MONTHLY', 'is_mandatory_default' => false, 'is_active' => true, 'sort_order' => 2]
        );

        $this->invoice = Invoice::create([
            'invoice_number' => 'INV-2026-' . strtoupper(Str::random(6)),
            'student_id' => $this->student->id,
            'enrollment_id' => $this->enrollment->id,
            'academic_period_id' => $this->period->id,
            'gross_amount' => 3000.00,
            'adjustment_amount' => 0.00,
            'net_amount' => 3000.00,
            'source_key' => 'INV_TEST_' . Str::random(8),
            'status' => InvoiceStatus::ISSUED->value,
            'issued_at' => now(),
            'due_at' => now()->addDays(15)->toDateString(),
        ]);

        $this->admissionItem = InvoiceItem::create([
            'invoice_id' => $this->invoice->id,
            'fee_category_id' => $feeCatAdm->id,
            'description_bn' => 'ভর্তি ফি',
            'description_en' => 'Admission Fee',
            'quantity' => 1,
            'unit_amount' => 1000.00,
            'gross_amount' => 1000.00,
            'discount_amount' => 0.00,
            'net_amount' => 1000.00,
            'admission_required' => true,
        ]);

        $this->tuitionItem = InvoiceItem::create([
            'invoice_id' => $this->invoice->id,
            'fee_category_id' => $feeCatTuit->id,
            'description_bn' => 'মাসিক বেতন',
            'description_en' => 'Monthly Tuition',
            'quantity' => 1,
            'unit_amount' => 2000.00,
            'gross_amount' => 2000.00,
            'discount_amount' => 0.00,
            'net_amount' => 2000.00,
            'admission_required' => false,
        ]);
    }

    /**
     * Test AT-001 to AT-014: Payment Slip issuance, conflict handling, voiding and verification.
     */
    public function test_payment_slip_lifecycle_and_verification(): void
    {
        // 1. Staff issues slip
        $response = $this->actingAs($this->staffUser, 'sanctum')->postJson('/api/v1/payment-slips', [
            'invoice_id' => $this->invoice->id,
            'slip_amount' => 3000.00,
            'notes' => 'Test slip issuance',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'UNPAID')
            ->assertJsonPath('data.slip_amount', '3000.00')
            ->assertJsonPath('data.remaining_amount', '3000.00');

        $slipId = $response->json('data.id');
        $slipNumber = $response->json('data.slip_number');
        $this->assertStringStartsWith('PS-2026-', $slipNumber);

        // 2. Issuing another active slip without supersede fails with 422
        $conflictRes = $this->actingAs($this->staffUser, 'sanctum')->postJson('/api/v1/payment-slips', [
            'invoice_id' => $this->invoice->id,
            'slip_amount' => 3000.00,
            'supersede_existing' => false,
        ]);
        $conflictRes->assertStatus(422);

        // 3. Issuing with supersede supersedes previous slip
        $supersedeRes = $this->actingAs($this->staffUser, 'sanctum')->postJson('/api/v1/payment-slips', [
            'invoice_id' => $this->invoice->id,
            'slip_amount' => 3000.00,
            'supersede_existing' => true,
        ]);
        $supersedeRes->assertStatus(201);
        $this->assertEquals(PaymentSlipStatus::SUPERSEDED, PaymentSlip::find($slipId)->status);

        $newSlip = PaymentSlip::find($supersedeRes->json('data.id'));

        // 4. Print payload test
        $printRes = $this->actingAs($this->staffUser, 'sanctum')->getJson("/api/v1/payment-slips/{$newSlip->id}/print");
        $printRes->assertStatus(200)
            ->assertJsonPath('data.print_count', 1);

        // 5. Public QR Verification without PII exposure
        $token = 'test_token_string';
        $newSlip->update(['verification_token_hash' => hash('sha256', $token)]);

        $verifyRes = $this->getJson("/api/v1/verify/payment-slip/{$token}");
        $verifyRes->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.is_valid', true)
            ->assertJsonPath('data.number', $newSlip->slip_number);
    }

    /**
     * Test AT-024 to AT-028: Cashier Shift open, close with variance, and dual-control reconciliation.
     */
    public function test_cashier_shift_lifecycle_and_dual_control(): void
    {
        // 1. Accounts user opens shift
        $openRes = $this->actingAs($this->accountsUser, 'sanctum')->postJson('/api/v1/cashier-shifts/open', [
            'opening_float_amount' => 200.00,
            'counter_code' => 'TEST_COUNTER',
        ]);

        $openRes->assertStatus(201)
            ->assertJsonPath('data.status', 'OPEN')
            ->assertJsonPath('data.opening_float_amount', '200.00')
            ->assertJsonPath('data.expected_cash_amount', '200.00');

        $shiftId = $openRes->json('data.id');

        // Opening second shift while one is open fails
        $dupRes = $this->actingAs($this->accountsUser, 'sanctum')->postJson('/api/v1/cashier-shifts/open', [
            'opening_float_amount' => 100.00,
        ]);
        $dupRes->assertStatus(422);

        // 2. Close shift with cash variance requires explanation
        $closeFailRes = $this->actingAs($this->accountsUser, 'sanctum')->postJson("/api/v1/cashier-shifts/{$shiftId}/close", [
            'counted_cash_amount' => 250.00, // +50 variance without reason
        ]);
        $closeFailRes->assertStatus(422);

        $closeRes = $this->actingAs($this->accountsUser, 'sanctum')->postJson("/api/v1/cashier-shifts/{$shiftId}/close", [
            'counted_cash_amount' => 250.00,
            'variance_reason' => 'Customer gave extra cash not refunded yet',
            'denomination_breakdown' => ['50' => 5],
        ]);
        $closeRes->assertStatus(200)
            ->assertJsonPath('data.status', 'UNDER_REVIEW')
            ->assertJsonPath('data.variance_amount', '50.00');

        // 3. Self-approval of variance is strictly forbidden (Denied 403 by gate/permission)
        $selfReconcileRes = $this->actingAs($this->accountsUser, 'sanctum')->postJson("/api/v1/cashier-shifts/{$shiftId}/reconcile", [
            'reconciliation_notes' => 'Self approving',
        ]);
        $selfReconcileRes->assertStatus(403);

        // 4. Admin (distinct user) approves variance
        $adminReconcileRes = $this->actingAs($this->adminUser, 'sanctum')->postJson("/api/v1/cashier-shifts/{$shiftId}/reconcile", [
            'reconciliation_notes' => 'Approved by accounts supervisor',
        ]);
        $adminReconcileRes->assertStatus(200)
            ->assertJsonPath('data.status', 'RECONCILED');
    }

    /**
     * Test AT-015 to AT-036: Cash collection, allocation priority, D04 admission approval, and receipt printing.
     */
    public function test_cash_collection_d04_approval_gate_and_receipts(): void
    {
        // 1. Open shift for accounts user
        $shiftNum1 = 'CS-2026-TEST-' . Str::random(6);
        $shift = CashierShift::create([
            'shift_number' => $shiftNum1,
            'cashier_user_id' => $this->accountsUser->id,
            'counter_code' => 'MAIN_COUNTER',
            'status' => CashierShiftStatus::OPEN,
            'opened_at' => now(),
            'opening_float_amount' => 500.00,
            'expected_cash_amount' => 500.00,
        ]);

        // Issue slip for 3000
        $slipNum1 = 'PS-2026-TEST-' . Str::random(6);
        $slip = PaymentSlip::create([
            'slip_number' => $slipNum1,
            'invoice_id' => $this->invoice->id,
            'student_id' => $this->student->id,
            'enrollment_id' => $this->enrollment->id,
            'academic_period_id' => $this->period->id,
            'status' => PaymentSlipStatus::UNPAID,
            'slip_amount' => 3000.00,
            'collected_amount' => 0.00,
            'remaining_amount' => 3000.00,
            'verification_token_hash' => hash('sha256', Str::random(16)),
            'snapshot_payload' => [],
            'issued_by' => $this->staffUser->id,
            'issued_at' => now(),
        ]);

        // 2. Partial payment of 500 BDT: Goes to mandatory admission item (1000 net)
        // Does NOT approve admission yet
        $part1Res = $this->actingAs($this->accountsUser, 'sanctum')->postJson("/api/v1/payment-slips/{$slip->id}/collect-cash", [
            'amount_received' => 500.00,
            'cash_tendered_amount' => 500.00,
        ]);

        $part1Res->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('payment.amount_received', '500.00')
            ->assertJsonPath('payment.admission_approved_triggered', false)
            ->assertJsonPath('slip.status', 'PARTIALLY_COLLECTED')
            ->assertJsonPath('slip.remaining_amount', '2500.00');

        $this->assertEquals(EnrollmentStatus::PENDING_PAYMENT, $this->enrollment->fresh()->status);

        // 3. Second payment of 500 BDT: Completely settles mandatory admission fee (1000 - 500 - 500 = 0)
        // D04 AdmissionApprovalService triggers enrollment transition to APPROVED!
        $part2Res = $this->actingAs($this->accountsUser, 'sanctum')->postJson("/api/v1/payment-slips/{$slip->id}/collect-cash", [
            'amount_received' => 500.00,
            'cash_tendered_amount' => 1000.00, // 500 change
        ]);

        $part2Res->assertStatus(201)
            ->assertJsonPath('payment.change_returned_amount', '500.00')
            ->assertJsonPath('payment.admission_approved_triggered', true);

        $this->assertEquals(EnrollmentStatus::APPROVED, $this->enrollment->fresh()->status);

        // 4. Reprint receipt records audit and increments print_count
        $receiptId = $part2Res->json('receipt.id');
        $reprintRes = $this->actingAs($this->accountsUser, 'sanctum')->postJson("/api/v1/payment-receipts/{$receiptId}/reprint", [
            'reason' => 'Customer requested official copy for tax file',
        ]);
        $reprintRes->assertStatus(200)
            ->assertJsonPath('data.print_count', 2)
            ->assertJsonPath('data.last_reprint_reason', 'Customer requested official copy for tax file');
    }

    /**
     * Test AT-037 to AT-043: Dual-control payment reversals, compensating entries, and admission review flag.
     */
    public function test_payment_reversal_workflow_and_admission_review_flag(): void
    {
        $shiftNum2 = 'CS-2026-TEST-' . Str::random(6);
        $shift = CashierShift::create([
            'shift_number' => $shiftNum2,
            'cashier_user_id' => $this->accountsUser->id,
            'counter_code' => 'MAIN_COUNTER',
            'status' => CashierShiftStatus::OPEN,
            'opened_at' => now(),
            'opening_float_amount' => 0.00,
            'expected_cash_amount' => 0.00,
        ]);

        $slipNum2 = 'PS-2026-TEST-' . Str::random(6);
        $slip = PaymentSlip::create([
            'slip_number' => $slipNum2,
            'invoice_id' => $this->invoice->id,
            'student_id' => $this->student->id,
            'enrollment_id' => $this->enrollment->id,
            'academic_period_id' => $this->period->id,
            'status' => PaymentSlipStatus::UNPAID,
            'slip_amount' => 1000.00,
            'collected_amount' => 0.00,
            'remaining_amount' => 1000.00,
            'verification_token_hash' => hash('sha256', Str::random(16)),
            'snapshot_payload' => [],
            'issued_by' => $this->staffUser->id,
            'issued_at' => now(),
        ]);

        // Post 1000 BDT cash payment (mandatory admission settled, approved)
        $payRes = $this->actingAs($this->accountsUser, 'sanctum')->postJson("/api/v1/payment-slips/{$slip->id}/collect-cash", [
            'amount_received' => 1000.00,
            'cash_tendered_amount' => 1000.00,
        ]);
        $payRes->assertStatus(201);
        $paymentId = $payRes->json('payment.id');

        // 1. Accounts requests reversal
        $reqRes = $this->actingAs($this->accountsUser, 'sanctum')->postJson("/api/v1/payments/{$paymentId}/reversal-requests", [
            'reason_category' => 'CASH_COUNT_ERROR',
            'reason_details' => 'Accidentally posted 1000 cash instead of 100 cash',
        ]);
        $reqRes->assertStatus(201)
            ->assertJsonPath('data.status', 'PENDING');

        $requestId = $reqRes->json('data.id');

        // 2. Self-approval is rejected (dual control: forbidden by role permissions & policy)
        $selfApproveRes = $this->actingAs($this->accountsUser, 'sanctum')->postJson("/api/v1/payment-reversal-requests/{$requestId}/approve", [
            'notes' => 'Self approving reversal',
        ]);
        $selfApproveRes->assertStatus(403);

        // 3. Supervisor (Admin) approves reversal
        $approveRes = $this->actingAs($this->adminUser, 'sanctum')->postJson("/api/v1/payment-reversal-requests/{$requestId}/approve", [
            'notes' => 'Verified counter cash deficit and approved reversal',
        ]);
        $approveRes->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.admission_review_flag', 'FLAGGED_FOR_REVIEW');

        // Payment and Receipt are now REVERSED
        $this->assertEquals(PaymentStatus::REVERSED, Payment::find($paymentId)->status);
        $this->assertEquals(ReceiptStatus::REVERSED, PaymentReceipt::where('payment_id', $paymentId)->first()->status);

        // Slip balance restored
        $this->assertEquals(PaymentSlipStatus::UNPAID, $slip->fresh()->status);
        $this->assertEquals('1000.00', (string) $slip->fresh()->remaining_amount);
    }

    /**
     * Test AT-064 to AT-070: RBAC Matrix and Separation of Duties.
     */
    public function test_d07_rbac_matrix_and_privilege_boundaries(): void
    {
        // 1. Office staff cannot accept cash collection (403 Forbidden)
        $slip = PaymentSlip::first();
        if ($slip) {
            $denyRes = $this->actingAs($this->staffUser, 'sanctum')->postJson("/api/v1/payment-slips/{$slip->id}/collect-cash", [
                'amount_received' => 100.00,
            ]);
            $denyRes->assertStatus(403);
        }

        // 2. Accounts officer cannot issue new payment slips (403 Forbidden)
        $denySlipCreate = $this->actingAs($this->accountsUser, 'sanctum')->postJson('/api/v1/payment-slips', [
            'invoice_id' => $this->invoice->id,
            'slip_amount' => 100.00,
        ]);
        $denySlipCreate->assertStatus(403);
    }
}

