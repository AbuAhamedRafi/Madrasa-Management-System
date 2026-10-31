<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentIdentifier extends Model
{
    protected $fillable = [
        'student_id',
        'namespace',
        'value_normalized',
        'value_raw',
        'issuer',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'student_id' => 'integer',
            'created_by' => 'integer',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

