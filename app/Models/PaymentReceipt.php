<?php

namespace App\Models;

use App\Enums\ReceiptStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentReceipt extends Model
{
    use HasFactory;

    protected $table = 'payment_receipts';

    protected $fillable = [
        'receipt_number',
        'payment_id',
        'payment_slip_id',
        'invoice_id',
        'student_id',
        'status',
        'receipt_amount',
        'verification_token_hash',
        'verification_token_encrypted',
        'snapshot_payload',
        'print_count',
        'last_printed_at',
        'last_reprint_reason',
        'issued_by',
        'issued_at',
        'reversed_at',
    ];

    protected $casts = [
        'status' => ReceiptStatus::class,
        'receipt_amount' => 'decimal:2',
        'print_count' => 'integer',
        'last_printed_at' => 'datetime',
        'issued_at' => 'datetime',
        'reversed_at' => 'datetime',
        'snapshot_payload' => 'array',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }

    public function paymentSlip(): BelongsTo
    {
        return $this->belongsTo(PaymentSlip::class, 'payment_slip_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(PaymentNotification::class, 'payment_receipt_id');
    }
}
