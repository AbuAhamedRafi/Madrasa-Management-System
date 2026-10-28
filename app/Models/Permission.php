<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Permission extends Model
{
    protected $fillable = [
        'key',
        'module',
        'label_bn',
        'label_en',
        'description_bn',
        'description_en',
        'display_order',
    ];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_permission')
            ->withPivot('access_level', 'created_at');
    }

    public function userGrants(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_permission_grants');
    }
}

