<?php

namespace App\Models;

use App\Enums\ReversalRequestStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PaymentReversalRequest extends Model
{
    use HasFactory;

    protected $table = 'payment_reversal_requests';

    protected $fillable = [
        'request_number',
        'payment_id',
        'invoice_id',
        'student_id',
        'status',
        'reason_category',
        'reason_details',
        'requested_by',
        'requested_at',
        'reviewed_by',
        'reviewed_at',
        'review_notes',
    ];

    protected $casts = [
        'status' => ReversalRequestStatus::class,
        'requested_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

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

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function reversal(): HasOne
    {
        return $this->hasOne(PaymentReversal::class, 'reversal_request_id');
    }
}

