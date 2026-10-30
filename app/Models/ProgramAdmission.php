<?php

namespace App\Models;

use App\Enums\AdmissionEntryType;
use App\Enums\AdmissionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProgramAdmission extends Model
{
    protected $table = 'program_admissions';

    protected $fillable = [
        'student_id',
        'academic_program_id',
        'academic_cohort_id',
        'entry_type',
        'status',
        'entered_at',
        'completed_at',
        'lock_version',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'entry_type' => AdmissionEntryType::class,
            'status' => AdmissionStatus::class,
            'entered_at' => 'datetime',
            'completed_at' => 'datetime',
            'lock_version' => 'integer',
            'created_by' => 'integer',
            'updated_by' => 'integer',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(AcademicProgram::class, 'academic_program_id');
    }

    public function cohort(): BelongsTo
    {
        return $this->belongsTo(AcademicCohort::class, 'academic_cohort_id');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class, 'program_admission_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
