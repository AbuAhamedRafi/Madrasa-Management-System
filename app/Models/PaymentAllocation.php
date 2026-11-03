<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentAllocation extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $table = 'payment_allocations';

    protected $fillable = [
        'payment_id',
        'payment_reversal_id',
        'invoice_id',
        'invoice_item_id',
        'direction',
        'allocated_amount',
        'admission_required',
        'item_balance_before',
        'item_balance_after',
        'created_by',
        'created_at',
    ];

    protected $casts = [
        'allocated_amount' => 'decimal:2',
        'admission_required' => 'boolean',
        'item_balance_before' => 'decimal:2',
        'item_balance_after' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }

    public function reversal(): BelongsTo
    {
        return $this->belongsTo(PaymentReversal::class, 'payment_reversal_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    public function invoiceItem(): BelongsTo
    {
        return $this->belongsTo(InvoiceItem::class, 'invoice_item_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

