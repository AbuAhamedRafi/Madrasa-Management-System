<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportMetricCache extends Model
{
    use HasFactory;

    protected $table = 'report_metric_caches';

    protected $fillable = [
        'cache_key',
        'metric_or_report_key',
        'actor_id',
        'authorized_scope_hash',
        'filter_hash',
        'report_revision',
        'payload_json',
        'source_watermarks_json',
        'expires_at',
    ];

    protected $casts = [
        'payload_json' => 'array',
        'source_watermarks_json' => 'array',
        'expires_at' => 'datetime',
    ];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
