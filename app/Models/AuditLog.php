<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'id',
        'actor_user_id',
        'subject_user_id',
        'action_code',
        'before_metadata',
        'after_metadata',
        'ip_hash',
        'request_id',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'before_metadata' => 'array',
            'after_metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subject_user_id');
    }
}

