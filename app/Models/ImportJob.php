<?php

namespace App\Models;

use App\Enums\ImportJobStatus;
use App\Enums\ImportMode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ImportJob extends Model
{
    use HasFactory;

    protected $fillable = [
        'job_number',
        'requester_id',
        'mode',
        'template_code',
        'template_version',
        'locale',
        'source_name',
        'storage_key',
        'sha256',
        'byte_size',
        'detected_sheets_json',
        'state',
        'lock_version',
        'mapping_version',
        'reviewed_version',
        'preview_token_hash',
        'preview_state_hash',
        'preview_expires_at',
        'total_rows',
        'ready_rows',
        'review_rows',
        'invalid_rows',
        'committed_rows',
        'skipped_rows',
        'failed_rows',
        'result_storage_key',
        'result_sha256',
        'scope_json',
        'requested_at',
        'started_at',
        'completed_at',
        'cancelled_at',
        'retention_until',
    ];

    protected $casts = [
        'mode' => ImportMode::class,
        'state' => ImportJobStatus::class,
        'detected_sheets_json' => 'array',
        'scope_json' => 'array',
        'requested_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'retention_until' => 'datetime',
        'preview_expires_at' => 'datetime',
        'total_rows' => 'integer',
        'ready_rows' => 'integer',
        'review_rows' => 'integer',
        'invalid_rows' => 'integer',
        'committed_rows' => 'integer',
        'skipped_rows' => 'integer',
        'failed_rows' => 'integer',
        'lock_version' => 'integer',
        'mapping_version' => 'integer',
        'reviewed_version' => 'integer',
    ];

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function mapping(): HasOne
    {
        return $this->hasOne(ImportColumnMapping::class, 'import_job_id')->latestOfMany();
    }

    public function mappings(): HasMany
    {
        return $this->hasMany(ImportColumnMapping::class, 'import_job_id');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(ImportRow::class, 'import_job_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ImportJobEvent::class, 'import_job_id');
    }
}
