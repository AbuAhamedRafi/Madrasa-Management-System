<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InvoiceItem extends Model
{
    protected $fillable = [
        'invoice_id',
        'fee_category_id',
        'fee_structure_item_id',
        'source_version',
        'description_bn',
        'description_en',
        'quantity',
        'unit_amount',
        'gross_amount',
        'discount_amount',
        'net_amount',
        'admission_required',
        'billing_month',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'source_version' => 'integer',
            'quantity' => 'integer',
            'unit_amount' => 'decimal:2',
            'gross_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'net_amount' => 'decimal:2',
            'admission_required' => 'boolean',
            'billing_month' => 'date:Y-m-d',
            'sort_order' => 'integer',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    public function feeCategory(): BelongsTo
    {
        return $this->belongsTo(FeeCategory::class, 'fee_category_id');
    }

    public function feeStructureItem(): BelongsTo
    {
        return $this->belongsTo(FeeStructureItem::class, 'fee_structure_item_id');
    }

    public function adjustmentRequests(): HasMany
    {
        return $this->hasMany(FeeAdjustmentRequest::class, 'invoice_item_id');
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(FeeAdjustment::class, 'invoice_item_id');
    }
}
