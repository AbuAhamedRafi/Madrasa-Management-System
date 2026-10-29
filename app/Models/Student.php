<?php

namespace App\Models;

use App\Enums\StudentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Crypt;

class Student extends Model
{
    protected $fillable = [
        'student_id',
        'name_bn',
        'name_en',
        'date_of_birth',
        'gender',
        'admission_date',
        'status',
        'birth_registration_ciphertext',
        'birth_registration_hash',
        'present_address_bn',
        'present_address_en',
        'permanent_address_bn',
        'permanent_address_en',
        'profile_photo_path',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'admission_date' => 'date',
            'status' => StudentStatus::class,
            'created_by' => 'integer',
            'updated_by' => 'integer',
        ];
    }

    public function getBirthRegistrationNumberAttribute(): ?string
    {
        if (empty($this->birth_registration_ciphertext)) {
            return null;
        }

        try {
            return Crypt::decryptString($this->birth_registration_ciphertext);
        } catch (\Throwable) {
            return null;
        }
    }

    public function setBirthRegistrationNumberAttribute(?string $value): void
    {
        if (empty($value)) {
            $this->attributes['birth_registration_ciphertext'] = null;
            $this->attributes['birth_registration_hash'] = null;
            return;
        }

        $normalized = preg_replace('/[^0-9]/', '', $value);
        $this->attributes['birth_registration_ciphertext'] = Crypt::encryptString($normalized);
        $this->attributes['birth_registration_hash'] = hash('sha256', $normalized);
    }

    public function studentGuardians(): HasMany
    {
        return $this->hasMany(StudentGuardian::class, 'student_id');
    }

    public function primaryStudentGuardian(): HasOne
    {
        return $this->hasOne(StudentGuardian::class, 'student_id')->where('is_primary', true);
    }

    public function guardians(): BelongsToMany
    {
        return $this->belongsToMany(Guardian::class, 'student_guardians')
            ->withPivot(['id', 'relation', 'is_primary', 'sms_consent', 'consent_at', 'consent_source', 'created_by', 'updated_by'])
            ->withTimestamps();
    }

    public function identifiers(): HasMany
    {
        return $this->hasMany(StudentIdentifier::class, 'student_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(StudentDocument::class, 'student_id');
    }

    public function duplicateReviews(): HasMany
    {
        return $this->hasMany(StudentDuplicateReview::class, 'student_id');
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

