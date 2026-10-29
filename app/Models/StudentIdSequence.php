<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudentIdSequence extends Model
{
    protected $fillable = [
        'namespace',
        'prefix',
        'last_value',
    ];

    protected function casts(): array
    {
        return [
            'last_value' => 'integer',
        ];
    }
}

