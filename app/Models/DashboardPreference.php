<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DashboardPreference extends Model
{
    use HasFactory;

    protected $table = 'dashboard_preferences';

    protected $fillable = [
        'user_id',
        'default_dashboard',
        'locale_override',
        'widgets_json',
        'favorite_filters_json',
        'version',
    ];

    protected $casts = [
        'widgets_json' => 'array',
        'favorite_filters_json' => 'array',
        'version' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
