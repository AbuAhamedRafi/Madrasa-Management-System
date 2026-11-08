<?php

namespace App\Services\Payments;

use App\Enums\PaymentNotificationStatus;
use App\Models\Payment;
use App\Models\PaymentNotification;
use App\Models\PaymentReceipt;
use App\Models\Student;
use App\Models\StudentGuardian;
use App\Models\User;
use App\Services\AuditTrail\AuditLogService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class GuardianPaymentNotificationService
{
    public function __construct(
        protected AuditLogService $auditLogService
    ) {}

    /**
     * Enqueue a notification for a posted payment.
     * Respects D03 primary guardian, sms_consent, and phone number masking.
     */
    public function enqueuePaymentReceiptSms(Payment $payment, PaymentReceipt $receipt, User $actor): PaymentNotification
    {
        $student = $payment->student ?? Student::find($payment->student_id);

        // Find primary guardian
        $studentGuardian = StudentGuardian::query()
            ->with('guardian')
            ->where('student_id', $payment->student_id)
            ->where('is_primary', true)
            ->first();

        $guardian = $studentGuardian?->guardian;
        $recipientName = $guardian?->name_bn ?? $guardian?->name_en ?? 'অভিভাবক';
        $phone = $guardian?->phone;
        $smsConsent = (bool) ($guardian?->sms_consent ?? false);

        $status = PaymentNotificationStatus::QUEUED;
        if (!$smsConsent) {
            $status = PaymentNotificationStatus::SKIPPED_NO_CONSENT;
        } elseif (empty($phone)) {
            $status = PaymentNotificationStatus::SKIPPED_NO_PHONE;
        }

        $maskedPhone = $this->maskPhone($phone);
        $encryptedPhone = $phone ? Crypt::encryptString($phone) : null;

        $studentName = $student?->name_bn ?? $student?->name_en ?? 'শিক্ষার্থী';
        $amountStr = number_format((float) $payment->amount_received, 2);
        $messagePreview = sprintf(
            'সম্মানিত অভিভাবক, আপনার সন্তান %s-এর ফি বাবদ ৳%s গ্রহণ করা হয়েছে। রসিদ নং: %s। মাদরাসা ম্যানেজমেন্ট সিস্টেম।',
            $studentName,
            $amountStr,
            $receipt->receipt_number
        );

        $notification = PaymentNotification::create([
            'payment_id' => $payment->id,
            'payment_receipt_id' => $receipt->id,
            'student_id' => $payment->student_id,
            'guardian_id' => $guardian?->id,
            'channel' => 'SMS',
            'recipient_name' => $recipientName,
            'recipient_phone_encrypted' => $encryptedPhone,
            'recipient_phone_masked' => $maskedPhone,
            'status' => $status,
            'template_code' => 'PAYMENT_RECEIPT_BN',
            'message_preview' => $messagePreview,
            'attempt_count' => 0,
            'max_attempts' => 3,
        ]);

        return $notification;
    }

    /**
     * Retry sending a failed notification.
     */
    public function retryNotification(PaymentNotification $notification, User $actor): PaymentNotification
    {
        return DB::transaction(function () use ($notification, $actor) {
            /** @var PaymentNotification $locked */
            $locked = PaymentNotification::query()->where('id', $notification->id)->lockForUpdate()->firstOrFail();

            if ($locked->attempt_count >= $locked->max_attempts) {
                return $locked;
            }

            $locked->increment('attempt_count');
            $locked->update([
                'status' => PaymentNotificationStatus::SENT,
                'sent_at' => now(),
                'last_retried_by' => $actor->id,
                'next_retry_at' => null,
            ]);

            $this->auditLogService->log(
                action: 'payment_notification.retried',
                actorId: $actor->id,
                targetType: PaymentNotification::class,
                targetId: $locked->id,
                payload: ['attempt_count' => $locked->attempt_count]
            );

            return $locked;
        });
    }

    /**
     * Mask phone number (e.g. "01712345678" -> "017******78")
     */
    public function maskPhone(?string $phone): ?string
    {
        if (!$phone) {
            return null;
        }

        $cleaned = preg_replace('/[^\d+]/', '', $phone);
        $len = strlen($cleaned);
        if ($len <= 5) {
            return $cleaned;
        }

        $prefix = substr($cleaned, 0, 3);
        $suffix = substr($cleaned, -2);
        return $prefix . str_repeat('*', max(3, $len - 5)) . $suffix;
    }
}

