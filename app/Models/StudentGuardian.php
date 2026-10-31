<?php

namespace App\Models;

use App\Enums\GuardianRelation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentGuardian extends Model
{
    protected $table = 'student_guardians';

    protected $fillable = [
        'student_id',
        'guardian_id',
        'relation',
        'is_primary',
        'sms_consent',
        'consent_at',
        'consent_source',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'student_id' => 'integer',
            'guardian_id' => 'integer',
            'relation' => GuardianRelation::class,
            'is_primary' => 'boolean',
            'sms_consent' => 'boolean',
            'consent_at' => 'datetime',
            'created_by' => 'integer',
            'updated_by' => 'integer',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function guardian(): BelongsTo
    {
        return $this->belongsTo(Guardian::class, 'guardian_id');
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

