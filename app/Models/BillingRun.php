<?php

namespace App\Models;

use App\Enums\BillingRunStatus;
use App\Enums\BillingRunType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BillingRun extends Model
{
    protected $fillable = [
        'run_number',
        'type',
        'academic_period_id',
        'academic_offering_id',
        'billing_month',
        'status',
        'requested_by',
        'idempotency_key',
        'request_hash',
        'preview_hash',
        'started_at',
        'completed_at',
        'notes',
        'lock_version',
    ];

    protected function casts(): array
    {
        return [
            'type' => BillingRunType::class,
            'status' => BillingRunStatus::class,
            'billing_month' => 'date:Y-m-d',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'lock_version' => 'integer',
        ];
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(AcademicPeriod::class, 'academic_period_id');
    }

    public function offering(): BelongsTo
    {
        return $this->belongsTo(AcademicOffering::class, 'academic_offering_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(BillingRunItem::class, 'billing_run_id');
    }
}

