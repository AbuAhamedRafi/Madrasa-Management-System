<?php

namespace App\Models;

use App\Enums\BillingRunItemStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingRunItem extends Model
{
    protected $fillable = [
        'billing_run_id',
        'enrollment_id',
        'source_key',
        'invoice_id',
        'status',
        'reason_code',
        'attempt_count',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'status' => BillingRunItemStatus::class,
            'attempt_count' => 'integer',
        ];
    }

    public function billingRun(): BelongsTo
    {
        return $this->belongsTo(BillingRun::class, 'billing_run_id');
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class, 'enrollment_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }
}

