<?php

namespace App\Models;

use App\Enums\PromotionAction;
use App\Enums\PromotionBatchMode;
use App\Enums\PromotionBatchStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PromotionBatch extends Model
{
    protected $table = 'promotion_batches';

    protected $fillable = [
        'batch_number',
        'mode',
        'action',
        'source_academic_period_id',
        'source_offering_section_id',
        'target_academic_period_id',
        'target_academic_offering_id',
        'status',
        'lock_version',
        'notes',
        'created_by',
        'submitted_by',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'mode' => PromotionBatchMode::class,
            'action' => PromotionAction::class,
            'status' => PromotionBatchStatus::class,
            'lock_version' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'created_by' => 'integer',
            'submitted_by' => 'integer',
        ];
    }

    public function sourceAcademicPeriod(): BelongsTo
    {
        return $this->belongsTo(AcademicPeriod::class, 'source_academic_period_id');
    }

    public function sourceOfferingSection(): BelongsTo
    {
        return $this->belongsTo(OfferingSection::class, 'source_offering_section_id');
    }

    public function targetAcademicPeriod(): BelongsTo
    {
        return $this->belongsTo(AcademicPeriod::class, 'target_academic_period_id');
    }

    public function targetAcademicOffering(): BelongsTo
    {
        return $this->belongsTo(AcademicOffering::class, 'target_academic_offering_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PromotionItem::class, 'batch_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(PromotionEvent::class, 'promotion_batch_id');
    }
}

