<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PromotionEvent extends Model
{
    public $timestamps = false;

    protected $table = 'promotion_events';

    protected $fillable = [
        'promotion_batch_id',
        'promotion_item_id',
        'actor_user_id',
        'action',
        'before_json',
        'after_json',
        'reason',
        'request_id',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'before_json' => 'array',
            'after_json' => 'array',
            'occurred_at' => 'datetime',
            'actor_user_id' => 'integer',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(PromotionBatch::class, 'promotion_batch_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(PromotionItem::class, 'promotion_item_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}

