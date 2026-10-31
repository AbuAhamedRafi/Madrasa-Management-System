<?php

namespace App\Models;

use App\Enums\PromotionAction;
use App\Enums\PromotionItemStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PromotionItem extends Model
{
    protected $table = 'promotion_items';

    protected $fillable = [
        'batch_id',
        'student_id',
        'source_enrollment_id',
        'target_offering_section_id',
        'action',
        'academic_eligibility_confirmed',
        'eligibility_confirmed_by',
        'eligibility_confirmed_at',
        'status',
        'target_enrollment_id',
        'error_code',
        'safe_error_message',
        'attempt_count',
    ];

    protected function casts(): array
    {
        return [
            'action' => PromotionAction::class,
            'status' => PromotionItemStatus::class,
            'academic_eligibility_confirmed' => 'boolean',
            'eligibility_confirmed_at' => 'datetime',
            'attempt_count' => 'integer',
            'eligibility_confirmed_by' => 'integer',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(PromotionBatch::class, 'batch_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function sourceEnrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class, 'source_enrollment_id');
    }

    public function targetOfferingSection(): BelongsTo
    {
        return $this->belongsTo(OfferingSection::class, 'target_offering_section_id');
    }

    public function targetEnrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class, 'target_enrollment_id');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'eligibility_confirmed_by');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(PromotionAttempt::class, 'promotion_item_id');
    }
}

