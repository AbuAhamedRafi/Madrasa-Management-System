<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

class Guardian extends Model
{
    protected $fillable = [
        'name_bn',
        'name_en',
        'phone',
        'alternative_phone',
        'address_bn',
        'address_en',
        'nid_number_ciphertext',
        'occupation',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'created_by' => 'integer',
            'updated_by' => 'integer',
        ];
    }

    public function getNidNumberAttribute(): ?string
    {
        if (empty($this->nid_number_ciphertext)) {
            return null;
        }

        try {
            return Crypt::decryptString($this->nid_number_ciphertext);
        } catch (\Throwable) {
            return null;
        }
    }

    public function setNidNumberAttribute(?string $value): void
    {
        if (empty($value)) {
            $this->attributes['nid_number_ciphertext'] = null;
            return;
        }

        $this->attributes['nid_number_ciphertext'] = Crypt::encryptString(trim($value));
    }

    public function students(): BelongsToMany
    {
        return $this->belongsToMany(Student::class, 'student_guardians')
            ->withPivot(['id', 'relation', 'is_primary', 'sms_consent', 'consent_at', 'consent_source', 'created_by', 'updated_by'])
            ->withTimestamps();
    }

    public function studentGuardians(): HasMany
    {
        return $this->hasMany(StudentGuardian::class, 'guardian_id');
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

