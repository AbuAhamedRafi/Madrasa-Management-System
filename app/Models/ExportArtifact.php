<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExportArtifact extends Model
{
    use HasFactory;

    protected $fillable = [
        'export_job_id',
        'filename',
        'sha256',
        'object_key',
        'byte_size',
        'mime',
        'expire_at',
        'purged_at',
    ];

    protected $casts = [
        'expire_at' => 'datetime',
        'purged_at' => 'datetime',
        'byte_size' => 'integer',
    ];

    public function exportJob(): BelongsTo
    {
        return $this->belongsTo(ExportJob::class, 'export_job_id');
    }
}
