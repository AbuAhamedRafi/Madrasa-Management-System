<?php

namespace App\Models;

use App\Enums\EnrollmentStatus;
use App\Enums\EnrollmentType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Enrollment extends Model
{
    protected $table = 'enrollments';

    protected $fillable = [
        'program_admission_id',
        'student_id',
        'academic_period_id',
        'academic_offering_id',
        'offering_section_id',
        'enrollment_type',
        'status',
        'billing_cleared',
        'payment_cleared',
        'lock_version',
        'submitted_at',
        'approved_at',
        'cancelled_at',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'enrollment_type' => EnrollmentType::class,
            'status' => EnrollmentStatus::class,
            'billing_cleared' => 'boolean',
            'payment_cleared' => 'boolean',
            'lock_version' => 'integer',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'created_by' => 'integer',
            'updated_by' => 'integer',
        ];
    }

    public function programAdmission(): BelongsTo
    {
        return $this->belongsTo(ProgramAdmission::class, 'program_admission_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(AcademicPeriod::class, 'academic_period_id');
    }

    public function offering(): BelongsTo
    {
        return $this->belongsTo(AcademicOffering::class, 'academic_offering_id');
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(OfferingSection::class, 'offering_section_id');
    }

    public function statusEvents(): HasMany
    {
        return $this->hasMany(EnrollmentStatusEvent::class, 'enrollment_id')->orderBy('occurred_at', 'asc');
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

