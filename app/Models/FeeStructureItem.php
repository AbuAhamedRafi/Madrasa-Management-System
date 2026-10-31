<?php

namespace App\Models;

use App\Enums\FeeCadence;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeeStructureItem extends Model
{
    protected $fillable = [
        'fee_structure_id',
        'fee_category_id',
        'cadence',
        'amount',
        'admission_required',
        'due_rule',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'cadence' => FeeCadence::class,
            'amount' => 'decimal:2',
            'admission_required' => 'boolean',
            'due_rule' => 'array',
            'sort_order' => 'integer',
        ];
    }

    public function feeStructure(): BelongsTo
    {
        return $this->belongsTo(FeeStructure::class, 'fee_structure_id');
    }

    public function feeCategory(): BelongsTo
    {
        return $this->belongsTo(FeeCategory::class, 'fee_category_id');
    }
}
