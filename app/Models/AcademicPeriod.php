<?php

namespace App\Models;

use App\Enums\PeriodState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AcademicPeriod extends Model
{
    protected $fillable = [
        'program_id',
        'cohort_id',
        'label',
        'calendar_year',
        'study_year_number',
        'start_date',
        'end_date',
        'state',
        'lock_version',
    ];

    protected function casts(): array
    {
        return [
            'program_id' => 'integer',
            'cohort_id' => 'integer',
            'calendar_year' => 'integer',
            'study_year_number' => 'integer',
            'start_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
            'state' => PeriodState::class,
            'lock_version' => 'integer',
        ];
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(AcademicProgram::class, 'program_id');
    }

    public function cohort(): BelongsTo
    {
        return $this->belongsTo(AcademicCohort::class, 'cohort_id');
    }

    public function offerings(): HasMany
    {
        return $this->hasMany(AcademicOffering::class, 'academic_period_id');
    }
}

