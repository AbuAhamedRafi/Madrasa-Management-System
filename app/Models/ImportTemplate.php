<?php

namespace App\Models;

use App\Enums\ImportMode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ImportTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name_bn',
        'name_en',
        'mode',
        'version',
        'locale',
        'scheme',
        'is_active',
        'schema_json',
        'checksum',
    ];

    protected $casts = [
        'mode' => ImportMode::class,
        'is_active' => 'boolean',
        'schema_json' => 'array',
    ];
}
