<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentIdempotency extends Model
{
    use HasFactory;

    protected $table = 'payment_idempotency';

    protected $fillable = [
        'idempotency_key',
        'operation',
        'actor_id',
        'request_hash',
        'response_code',
        'response_body',
        'resource_type',
        'resource_id',
        'expires_at',
    ];

    protected $casts = [
        'response_code' => 'integer',
        'response_body' => 'array',
        'expires_at' => 'datetime',
    ];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}

