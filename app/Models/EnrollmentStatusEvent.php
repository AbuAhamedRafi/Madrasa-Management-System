<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnrollmentStatusEvent extends Model
{
    protected $table = 'enrollment_status_events';

    public $timestamps = false;

    protected $fillable = [
        'enrollment_id',
        'from_status',
        'to_status',
        'reason',
        'actor_id',
        'source_type',
        'source_reference',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'enrollment_id' => 'integer',
            'actor_id' => 'integer',
            'occurred_at' => 'datetime',
        ];
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class, 'enrollment_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}

