<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EducationAuthority extends Model
{
    protected $fillable = [
        'code',
        'name_bn',
        'name_en',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function levels(): HasMany
    {
        return $this->hasMany(EducationLevel::class, 'authority_id')
            ->orderBy('sort_order')
            ->orderBy('code');
    }
}

