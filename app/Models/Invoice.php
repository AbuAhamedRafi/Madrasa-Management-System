<?php

namespace App\Models;

use App\Enums\InvoiceSourceType;
use App\Enums\InvoiceStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Invoice extends Model
{
    protected $fillable = [
        'invoice_number',
        'student_id',
        'enrollment_id',
        'academic_period_id',
        'fee_structure_id',
        'source_type',
        'source_key',
        'currency',
        'gross_amount',
        'adjustment_amount',
        'net_amount',
        'zero_charge_policy',
        'status',
        'issued_at',
        'due_at',
        'voided_at',
        'void_reason',
        'voided_by',
        'notes',
        'lock_version',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'source_type' => InvoiceSourceType::class,
            'status' => InvoiceStatus::class,
            'gross_amount' => 'decimal:2',
            'adjustment_amount' => 'decimal:2',
            'net_amount' => 'decimal:2',
            'zero_charge_policy' => 'boolean',
            'issued_at' => 'datetime',
            'due_at' => 'date:Y-m-d',
            'voided_at' => 'datetime',
            'lock_version' => 'integer',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class, 'enrollment_id');
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(AcademicPeriod::class, 'academic_period_id');
    }

    public function feeStructure(): BelongsTo
    {
        return $this->belongsTo(FeeStructure::class, 'fee_structure_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class, 'invoice_id')->orderBy('sort_order');
    }

    public function activeInstallmentPlan(): HasOne
    {
        return $this->hasOne(InstallmentPlan::class, 'invoice_id')->where('status', 'ACTIVE');
    }

    public function installmentPlans(): HasMany
    {
        return $this->hasMany(InstallmentPlan::class, 'invoice_id');
    }
}

