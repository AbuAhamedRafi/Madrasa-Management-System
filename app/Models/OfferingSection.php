<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OfferingSection extends Model
{
    protected $fillable = [
        'academic_offering_id',
        'code',
        'name_bn',
        'name_en',
        'capacity',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'academic_offering_id' => 'integer',
            'capacity' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function offering(): BelongsTo
    {
        return $this->belongsTo(AcademicOffering::class, 'academic_offering_id');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class, 'offering_section_id');
    }
}

