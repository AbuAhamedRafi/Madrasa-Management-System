<?php

namespace App\Models;

use App\Enums\FeeStructureStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FeeStructure extends Model
{
    protected $fillable = [
        'program_id',
        'academic_period_id',
        'academic_offering_id',
        'program_class_id',
        'effective_from',
        'effective_to',
        'version',
        'status',
        'notes',
        'lock_version',
        'created_by',
        'published_by',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => FeeStructureStatus::class,
            'effective_from' => 'date:Y-m-d',
            'effective_to' => 'date:Y-m-d',
            'version' => 'integer',
            'lock_version' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(AcademicProgram::class, 'program_id');
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(AcademicPeriod::class, 'academic_period_id');
    }

    public function offering(): BelongsTo
    {
        return $this->belongsTo(AcademicOffering::class, 'academic_offering_id');
    }

    public function programClass(): BelongsTo
    {
        return $this->belongsTo(ProgramClass::class, 'program_class_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(FeeStructureItem::class, 'fee_structure_id')->orderBy('sort_order');
    }
}

