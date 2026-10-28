<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IdSequence extends Model
{
    protected $primaryKey = 'scope';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'scope',
        'prefix',
        'current_value',
        'padding',
    ];
}

