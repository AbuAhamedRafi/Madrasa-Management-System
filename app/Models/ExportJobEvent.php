<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExportJobEvent extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'export_job_id',
        'actor_id',
        'event',
        'context_redacted_json',
        'created_at',
    ];

    protected $casts = [
        'context_redacted_json' => 'array',
        'created_at' => 'datetime',
    ];

    public function exportJob(): BelongsTo
    {
        return $this->belongsTo(ExportJob::class, 'export_job_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
