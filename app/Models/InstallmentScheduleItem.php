<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstallmentScheduleItem extends Model
{
    protected $fillable = [
        'installment_plan_id',
        'installment_no',
        'due_date',
        'scheduled_amount',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'installment_no' => 'integer',
            'due_date' => 'date:Y-m-d',
            'scheduled_amount' => 'decimal:2',
        ];
    }

    public function installmentPlan(): BelongsTo
    {
        return $this->belongsTo(InstallmentPlan::class, 'installment_plan_id');
    }
}
