<?php

namespace App\Models;

use App\Enums\CohortStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AcademicCohort extends Model
{
    protected $fillable = [
        'program_id',
        'label',
        'intake_year',
        'description',
        'start_date',
        'end_date',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'program_id' => 'integer',
            'intake_year' => 'integer',
            'start_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
            'status' => CohortStatus::class,
        ];
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(AcademicProgram::class, 'program_id');
    }

    public function periods(): HasMany
    {
        return $this->hasMany(AcademicPeriod::class, 'cohort_id')
            ->orderBy('study_year_number')
            ->orderBy('start_date');
    }
}

