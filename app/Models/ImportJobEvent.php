<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportJobEvent extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'import_job_id',
        'actor_id',
        'event',
        'before_state',
        'after_state',
        'reason_code',
        'context_redacted_json',
        'created_at',
    ];

    protected $casts = [
        'context_redacted_json' => 'array',
        'created_at' => 'datetime',
    ];

    public function importJob(): BelongsTo
    {
        return $this->belongsTo(ImportJob::class, 'import_job_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
