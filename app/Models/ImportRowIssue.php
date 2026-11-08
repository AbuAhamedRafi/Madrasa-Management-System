<?php

namespace App\Models;

use App\Enums\ImportIssueSeverity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportRowIssue extends Model
{
    use HasFactory;

    protected $fillable = [
        'import_row_id',
        'code',
        'severity',
        'field',
        'source_column',
        'explanation_key',
        'message_bn',
        'message_en',
        'meta_json',
    ];

    protected $casts = [
        'severity' => ImportIssueSeverity::class,
        'meta_json' => 'array',
    ];

    public function row(): BelongsTo
    {
        return $this->belongsTo(ImportRow::class, 'import_row_id');
    }
}
