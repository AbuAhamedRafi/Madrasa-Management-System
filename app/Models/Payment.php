<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Payment extends Model
{
    use HasFactory;

    protected $table = 'payments';

    protected $fillable = [
        'payment_reference',
        'payment_slip_id',
        'invoice_id',
        'student_id',
        'enrollment_id',
        'academic_period_id',
        'cashier_shift_id',
        'payment_method',
        'status',
        'amount_received',
        'allocated_amount',
        'cash_tendered_amount',
        'change_returned_amount',
        'collected_by',
        'collected_at',
        'counter_code',
        'remarks',
        'admission_approved_triggered',
        'idempotency_key',
        'request_id',
    ];

    protected $casts = [
        'status' => PaymentStatus::class,
        'amount_received' => 'decimal:2',
        'allocated_amount' => 'decimal:2',
        'cash_tendered_amount' => 'decimal:2',
        'change_returned_amount' => 'decimal:2',
        'collected_at' => 'datetime',
        'admission_approved_triggered' => 'boolean',
    ];

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

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class, 'enrollment_id');
    }

    public function academicPeriod(): BelongsTo
    {
        return $this->belongsTo(AcademicPeriod::class, 'academic_period_id');
    }

    public function cashierShift(): BelongsTo
    {
        return $this->belongsTo(CashierShift::class, 'cashier_shift_id');
    }

    public function collector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'collected_by');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class, 'payment_id');
    }

    public function receipt(): HasOne
    {
        return $this->hasOne(PaymentReceipt::class, 'payment_id');
    }

    public function reversal(): HasOne
    {
        return $this->hasOne(PaymentReversal::class, 'payment_id');
    }

    public function reversalRequests(): HasMany
    {
        return $this->hasMany(PaymentReversalRequest::class, 'payment_id');
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(PaymentNotification::class, 'payment_id');
    }
}

