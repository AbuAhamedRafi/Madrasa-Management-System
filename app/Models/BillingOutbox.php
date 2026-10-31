<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BillingOutbox extends Model
{
    protected $table = 'billing_outbox';

    protected $fillable = [
        'event_id',
        'event_type',
        'aggregate_type',
        'aggregate_id',
        'dedupe_key',
        'payload_json',
        'published_at',
        'retry_count',
    ];

    protected function casts(): array
    {
        return [
            'payload_json' => 'array',
            'published_at' => 'datetime',
            'retry_count' => 'integer',
        ];
    }
}
