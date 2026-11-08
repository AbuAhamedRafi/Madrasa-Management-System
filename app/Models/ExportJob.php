<?php

namespace App\Models;

use App\Enums\ExportDatasetKey;
use App\Enums\ExportFormat;
use App\Enums\ExportJobStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ExportJob extends Model
{
    use HasFactory;

    protected $fillable = [
        'job_number',
        'requester_id',
        'dataset_key',
        'filter_json',
        'column_json',
        'scope_snapshot_json',
        'locale',
        'format',
        'row_count',
        'cutoff_at',
        'status',
        'storage_key',
        'expires_at',
        'completed_at',
        'cancelled_at',
        'error_code',
        'error_message',
    ];

    protected $casts = [
        'dataset_key' => ExportDatasetKey::class,
        'format' => ExportFormat::class,
        'status' => ExportJobStatus::class,
        'filter_json' => 'array',
        'column_json' => 'array',
        'scope_snapshot_json' => 'array',
        'cutoff_at' => 'datetime',
        'expires_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'row_count' => 'integer',
    ];

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ExportJobEvent::class, 'export_job_id');
    }

    public function artifact(): HasOne
    {
        return $this->hasOne(ExportArtifact::class, 'export_job_id')->latestOfMany();
    }

    public function artifacts(): HasMany
    {
        return $this->hasMany(ExportArtifact::class, 'export_job_id');
    }
}
