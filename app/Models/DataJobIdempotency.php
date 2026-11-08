<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DataJobIdempotency extends Model
{
    use HasFactory;

    protected $table = 'data_job_idempotency';

    protected $fillable = [
        'actor_id',
        'action',
        'idempotency_key',
        'request_hash',
        'result_type',
        'result_id',
        'response_code',
        'response_json',
        'state',
        'expires_at',
    ];

    protected $casts = [
        'response_json' => 'array',
        'response_code' => 'integer',
        'result_id' => 'integer',
        'expires_at' => 'datetime',
    ];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
