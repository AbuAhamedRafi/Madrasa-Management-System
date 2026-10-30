<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnrollmentRequestKey extends Model
{
    protected $table = 'enrollment_request_keys';

    protected $fillable = [
        'actor_id',
        'idempotency_key',
        'request_hash',
        'enrollment_id',
        'state',
        'response_code',
        'response_body',
    ];

    protected function casts(): array
    {
        return [
            'actor_id' => 'integer',
            'enrollment_id' => 'integer',
            'response_code' => 'integer',
            'response_body' => 'array',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class, 'enrollment_id');
    }
}

