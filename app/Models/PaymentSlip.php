<?php

namespace App\Models;

use App\Enums\PaymentSlipStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PaymentSlip extends Model
{
    use HasFactory;

    protected $table = 'payment_slips';

    protected $fillable = [
        'slip_number',
        'invoice_id',
        'student_id',
        'enrollment_id',
        'academic_period_id',
        'status',
        'slip_amount',
        'collected_amount',
        'remaining_amount',
        'expires_at',
        'verification_token_hash',
        'verification_token_encrypted',
        'snapshot_payload',
        'print_count',
        'last_printed_at',
        'issued_by',
        'issued_at',
        'voided_by',
        'voided_at',
        'void_reason',
        'superseded_by_slip_id',
        'notes',
    ];

    protected $casts = [
        'status' => PaymentSlipStatus::class,
        'slip_amount' => 'decimal:2',
        'collected_amount' => 'decimal:2',
        'remaining_amount' => 'decimal:2',
        'expires_at' => 'datetime',
        'last_printed_at' => 'datetime',
        'issued_at' => 'datetime',
        'voided_at' => 'datetime',
        'snapshot_payload' => 'array',
        'print_count' => 'integer',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class, 'enrollment_id');
    }

    public function academicPeriod(): BelongsTo
    {
        return $this->belongsTo(AcademicPeriod::class, 'academic_period_id');
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(PaymentSlip::class, 'superseded_by_slip_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'payment_slip_id');
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(PaymentReceipt::class, 'payment_slip_id');
    }
}
