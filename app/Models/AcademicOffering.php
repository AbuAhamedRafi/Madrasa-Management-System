<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AcademicOffering extends Model
{
    protected $fillable = [
        'academic_period_id',
        'program_class_id',
        'academic_track_id',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'academic_period_id' => 'integer',
            'program_class_id' => 'integer',
            'academic_track_id' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(AcademicPeriod::class, 'academic_period_id');
    }

    public function programClass(): BelongsTo
    {
        return $this->belongsTo(ProgramClass::class, 'program_class_id');
    }

    public function track(): BelongsTo
    {
        return $this->belongsTo(AcademicTrack::class, 'academic_track_id');
    }

    public function sections(): HasMany
    {
        return $this->hasMany(OfferingSection::class, 'academic_offering_id')
            ->orderBy('code');
    }
}

