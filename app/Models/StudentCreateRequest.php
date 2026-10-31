<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentCreateRequest extends Model
{
    protected $fillable = [
        'actor_id',
        'idempotency_key',
        'request_hash',
        'response_payload',
        'student_id',
    ];

    protected function casts(): array
    {
        return [
            'actor_id' => 'integer',
            'student_id' => 'integer',
            'response_payload' => 'array',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }
}

