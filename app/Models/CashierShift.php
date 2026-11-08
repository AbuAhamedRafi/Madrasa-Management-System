<?php

namespace App\Models;

use App\Enums\CashierShiftStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CashierShift extends Model
{
    use HasFactory;

    protected $table = 'cashier_shifts';

    protected $fillable = [
        'shift_number',
        'cashier_user_id',
        'counter_code',
        'status',
        'opened_at',
        'closed_at',
        'opening_float_amount',
        'expected_cash_amount',
        'counted_cash_amount',
        'variance_amount',
        'denomination_breakdown',
        'closing_notes',
        'variance_reason',
        'reconciled_by',
        'reconciled_at',
        'reconciliation_notes',
    ];

    protected $casts = [
        'status' => CashierShiftStatus::class,
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'reconciled_at' => 'datetime',
        'opening_float_amount' => 'decimal:2',
        'expected_cash_amount' => 'decimal:2',
        'counted_cash_amount' => 'decimal:2',
        'variance_amount' => 'decimal:2',
        'denomination_breakdown' => 'array',
    ];

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_user_id');
    }

    public function reconciler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reconciled_by');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'cashier_shift_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(CashierShiftEvent::class, 'cashier_shift_id');
    }
}

