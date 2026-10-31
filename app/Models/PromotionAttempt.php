<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PromotionAttempt extends Model
{
    public $timestamps = false;

    protected $table = 'promotion_attempts';

    protected $fillable = [
        'promotion_item_id',
        'attempt_number',
        'idempotency_key',
        'request_hash',
        'status',
        'started_at',
        'finished_at',
        'error_code',
        'result_json',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'attempt_number' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'created_at' => 'datetime',
            'result_json' => 'array',
        ];
    }

    public function promotionItem(): BelongsTo
    {
        return $this->belongsTo(PromotionItem::class, 'promotion_item_id');
    }
}

