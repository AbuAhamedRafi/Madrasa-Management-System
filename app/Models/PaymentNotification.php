<?php

namespace App\Models;

use App\Enums\PaymentNotificationStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentNotification extends Model
{
    use HasFactory;

    protected $table = 'payment_notifications';

    protected $fillable = [
        'payment_id',
        'payment_receipt_id',
        'student_id',
        'guardian_id',
        'channel',
        'recipient_name',
        'recipient_phone_encrypted',
        'recipient_phone_masked',
        'status',
        'template_code',
        'message_preview',
        'attempt_count',
        'max_attempts',
        'next_retry_at',
        'last_error_code',
        'last_error_message',
        'provider_message_id',
        'sent_at',
        'delivered_at',
        'last_retried_by',
    ];

    protected $casts = [
        'status' => PaymentNotificationStatus::class,
        'attempt_count' => 'integer',
        'max_attempts' => 'integer',
        'next_retry_at' => 'datetime',
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(PaymentReceipt::class, 'payment_receipt_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function guardian(): BelongsTo
    {
        return $this->belongsTo(Guardian::class, 'guardian_id');
    }

    public function lastRetriedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_retried_by');
    }
}

