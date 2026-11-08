<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ReportSavedView extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'report_saved_views';

    protected $fillable = [
        'owner_user_id',
        'report_key',
        'name',
        'visibility',
        'shared_roles_json',
        'filters_json',
        'columns_json',
        'sort_json',
        'version',
    ];

    protected $casts = [
        'shared_roles_json' => 'array',
        'filters_json' => 'array',
        'columns_json' => 'array',
        'sort_json' => 'array',
        'version' => 'integer',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }
}
