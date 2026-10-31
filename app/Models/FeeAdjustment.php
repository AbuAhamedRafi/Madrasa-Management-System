<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeeAdjustment extends Model
{
    protected $fillable = [
        'request_id',
        'invoice_item_id',
        'amount',
        'applied_at',
        'snapshot_after',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'applied_at' => 'datetime',
            'snapshot_after' => 'array',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(FeeAdjustmentRequest::class, 'request_id');
    }

    public function invoiceItem(): BelongsTo
    {
        return $this->belongsTo(InvoiceItem::class, 'invoice_item_id');
    }
}
