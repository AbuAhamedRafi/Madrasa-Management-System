<?php

namespace App\Models;

use App\Enums\FeeBillingType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FeeCategory extends Model
{
    protected $fillable = [
        'code',
        'name_bn',
        'name_en',
        'billing_type',
        'description_bn',
        'description_en',
        'is_active',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'billing_type' => FeeBillingType::class,
            'is_active' => 'boolean',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function structureItems(): HasMany
    {
        return $this->hasMany(FeeStructureItem::class, 'fee_category_id');
    }

    public function invoiceItems(): HasMany
    {
        return $this->hasMany(InvoiceItem::class, 'fee_category_id');
    }
}

