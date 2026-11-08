<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportColumnMapping extends Model
{
    use HasFactory;

    protected $fillable = [
        'import_job_id',
        'schema_version',
        'sheet',
        'header_row',
        'mapping_json',
        'options_json',
        'version',
        'created_by',
    ];

    protected $casts = [
        'mapping_json' => 'array',
        'options_json' => 'array',
        'header_row' => 'integer',
        'version' => 'integer',
    ];

    public function importJob(): BelongsTo
    {
        return $this->belongsTo(ImportJob::class, 'import_job_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
