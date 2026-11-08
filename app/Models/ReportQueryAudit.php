<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportQueryAudit extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $table = 'report_query_audits';

    protected $fillable = [
        'actor_id',
        'action_type',
        'report_key',
        'mode',
        'filter_hash',
        'authorized_scope_hash',
        'redacted_filters_json',
        'row_count',
        'duration_ms',
        'outcome',
        'source_watermarks_json',
        'created_at',
    ];

    protected $casts = [
        'redacted_filters_json' => 'array',
        'source_watermarks_json' => 'array',
        'row_count' => 'integer',
        'duration_ms' => 'integer',
        'created_at' => 'datetime',
    ];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
