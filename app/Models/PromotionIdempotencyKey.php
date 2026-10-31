<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PromotionIdempotencyKey extends Model
{
    public $timestamps = false;

    protected $table = 'promotion_idempotency_keys';

    protected $fillable = [
        'key',
        'scope',
        'request_hash',
        'outcome_ref',
        'state',
        'response_code',
        'response_body',
        'created_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'response_code' => 'integer',
            'response_body' => 'array',
            'created_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }
}

