<?php

namespace App\Models;

use App\Enums\AcademicScheme;
use App\Enums\ProgramState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AcademicProgram extends Model
{
    protected $fillable = [
        'code',
        'department_id',
        'education_level_id',
        'name_bn',
        'name_en',
        'description_bn',
        'description_en',
        'scheme',
        'duration_years',
        'state',
    ];

    protected function casts(): array
    {
        return [
            'department_id' => 'integer',
            'education_level_id' => 'integer',
            'scheme' => AcademicScheme::class,
            'duration_years' => 'integer',
            'state' => ProgramState::class,
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function educationLevel(): BelongsTo
    {
        return $this->belongsTo(EducationLevel::class, 'education_level_id');
    }

    public function classes(): HasMany
    {
        return $this->hasMany(ProgramClass::class, 'program_id')
            ->orderBy('stage_number')
            ->orderBy('sort_order')
            ->orderBy('code');
    }

    public function tracks(): HasMany
    {
        return $this->hasMany(AcademicTrack::class, 'program_id')
            ->orderBy('code');
    }

    public function cohorts(): HasMany
    {
        return $this->hasMany(AcademicCohort::class, 'program_id')
            ->orderByDesc('intake_year')
            ->orderBy('label');
    }

    public function periods(): HasMany
    {
        return $this->hasMany(AcademicPeriod::class, 'program_id')
            ->orderByDesc('start_date')
            ->orderBy('study_year_number');
    }

    public function programAdmissions(): HasMany
    {
        return $this->hasMany(ProgramAdmission::class, 'academic_program_id');
    }
}

