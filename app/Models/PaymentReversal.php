<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentReversal extends Model
{
    use HasFactory;

    protected $table = 'payment_reversals';

    protected $fillable = [
        'reversal_reference',
        'reversal_request_id',
        'payment_id',
        'invoice_id',
        'student_id',
        'enrollment_id',
        'cashier_shift_id',
        'reversed_amount',
        'admission_review_flag',
        'approved_by',
        'reversed_at',
        'notes',
    ];

    protected $casts = [
        'reversed_amount' => 'decimal:2',
        'reversed_at' => 'datetime',
    ];

    public function reversalRequest(): BelongsTo
    {
        return $this->belongsTo(PaymentReversalRequest::class, 'reversal_request_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id');
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

    public function cashierShift(): BelongsTo
    {
        return $this->belongsTo(CashierShift::class, 'cashier_shift_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class, 'payment_reversal_id');
    }
}

