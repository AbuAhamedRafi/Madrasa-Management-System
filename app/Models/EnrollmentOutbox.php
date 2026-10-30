<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class EnrollmentOutbox extends Model
{
    use HasUuids;

    protected $table = 'enrollment_outbox';

    public $timestamps = false;

    protected $fillable = [
        'id',
        'event_type',
        'aggregate_type',
        'aggregate_id',
        'payload_jsonb',
        'published_at',
        'attempts',
        'last_error',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'aggregate_id' => 'integer',
            'payload_jsonb' => 'array',
            'published_at' => 'datetime',
            'attempts' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
