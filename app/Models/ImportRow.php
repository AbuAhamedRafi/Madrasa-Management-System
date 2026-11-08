<?php

namespace App\Models;

use App\Enums\ImportRowAction;
use App\Enums\ImportRowStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImportRow extends Model
{
    use HasFactory;

    protected $fillable = [
        'import_job_id',
        'sheet',
        'row_number',
        'row_hash',
        'raw_payload_json',
        'normalized_payload_json',
        'status',
        'action',
        'target_student_pk',
        'target_student_version',
        'candidate_matches_json',
        'diff_json',
        'decision_json',
        'validation_summary_json',
        'outcome_json',
        'attempt_count',
        'applied_at',
    ];

    protected $casts = [
        'status' => ImportRowStatus::class,
        'action' => ImportRowAction::class,
        'raw_payload_json' => 'array',
        'normalized_payload_json' => 'array',
        'candidate_matches_json' => 'array',
        'diff_json' => 'array',
        'decision_json' => 'array',
        'validation_summary_json' => 'array',
        'outcome_json' => 'array',
        'applied_at' => 'datetime',
        'row_number' => 'integer',
        'attempt_count' => 'integer',
    ];

    public function importJob(): BelongsTo
    {
        return $this->belongsTo(ImportJob::class, 'import_job_id');
    }

    public function targetStudent(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'target_student_pk');
    }

    public function issues(): HasMany
    {
        return $this->hasMany(ImportRowIssue::class, 'import_row_id');
    }
}
