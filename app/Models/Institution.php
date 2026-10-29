<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Institution extends Model
{
    protected $fillable = [
        'code',
        'name_bn',
        'name_en',
        'address_bn',
        'address_en',
        'phone',
        'logo_path',
        'default_locale',
        'timezone',
    ];
}

