<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BillingEventInbox extends Model
{
    protected $table = 'billing_event_inbox';

    protected $fillable = [
        'event_id',
        'event_type',
        'aggregate_type',
        'aggregate_id',
        'payload_hash',
        'payload_json',
        'status',
        'processed_at',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'payload_json' => 'array',
            'processed_at' => 'datetime',
        ];
    }
}

