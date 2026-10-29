<?php

namespace App\Models;

use App\Enums\DocumentType;
use App\Enums\DocumentVerificationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentDocument extends Model
{
    protected $fillable = [
        'student_id',
        'type',
        'file_name',
        'storage_key',
        'mime_type',
        'size_bytes',
        'sha256',
        'verification_status',
        'uploaded_by',
        'verified_by',
        'verification_reason',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'student_id' => 'integer',
            'type' => DocumentType::class,
            'size_bytes' => 'integer',
            'verification_status' => DocumentVerificationStatus::class,
            'uploaded_by' => 'integer',
            'verified_by' => 'integer',
            'archived_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}

