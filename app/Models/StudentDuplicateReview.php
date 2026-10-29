<?php

namespace App\Models;

use App\Enums\DuplicateReviewStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentDuplicateReview extends Model
{
    protected $fillable = [
        'proposed_payload_hash',
        'student_id',
        'candidate_matches',
        'submitted_by',
        'assigned_to',
        'status',
        'decision_reason',
        'approved_by',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'student_id' => 'integer',
            'candidate_matches' => 'array',
            'submitted_by' => 'integer',
            'assigned_to' => 'integer',
            'status' => DuplicateReviewStatus::class,
            'approved_by' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}

