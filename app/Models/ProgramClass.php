<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProgramClass extends Model
{
    protected $fillable = [
        'program_id',
        'code',
        'name_bn',
        'name_en',
        'stage_number',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'program_id' => 'integer',
            'stage_number' => 'integer',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(AcademicProgram::class, 'program_id');
    }

    public function offerings(): HasMany
    {
        return $this->hasMany(AcademicOffering::class, 'program_class_id');
    }
}

