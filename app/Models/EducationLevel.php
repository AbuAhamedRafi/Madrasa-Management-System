<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EducationLevel extends Model
{
    protected $fillable = [
        'authority_id',
        'code',
        'name_bn',
        'name_en',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'authority_id' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function authority(): BelongsTo
    {
        return $this->belongsTo(EducationAuthority::class, 'authority_id');
    }

    public function programs(): HasMany
    {
        return $this->hasMany(AcademicProgram::class, 'education_level_id');
    }
}

