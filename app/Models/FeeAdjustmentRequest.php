<?php

namespace App\Models;

use App\Enums\AdjustmentState;
use App\Enums\AdjustmentType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class FeeAdjustmentRequest extends Model
{
    protected $fillable = [
        'invoice_item_id',
        'adjustment_type',
        'requested_amount',
        'reason',
        'supporting_reference',
        'state',
        'requested_by',
        'decided_by',
        'decided_at',
        'decision_notes',
        'lock_version',
    ];

    protected function casts(): array
    {
        return [
            'adjustment_type' => AdjustmentType::class,
            'state' => AdjustmentState::class,
            'requested_amount' => 'decimal:2',
            'decided_at' => 'datetime',
            'lock_version' => 'integer',
        ];
    }

    public function invoiceItem(): BelongsTo
    {
        return $this->belongsTo(InvoiceItem::class, 'invoice_item_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function adjustment(): HasOne
    {
        return $this->hasOne(FeeAdjustment::class, 'request_id');
    }
}

