<?php

namespace App\Models;

use App\Enums\InstallmentPlanStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InstallmentPlan extends Model
{
    protected $fillable = [
        'invoice_id',
        'status',
        'version',
        'lock_version',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => InstallmentPlanStatus::class,
            'version' => 'integer',
            'lock_version' => 'integer',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(InstallmentScheduleItem::class, 'installment_plan_id')->orderBy('installment_no');
    }
}

