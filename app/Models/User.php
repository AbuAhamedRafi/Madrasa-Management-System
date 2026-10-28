<?php

namespace App\Models;

use App\Enums\AccountStatus;
use App\Enums\SupportedLocale;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'public_id',
        'name_bn',
        'name_en',
        'username',
        'email',
        'phone',
        'password',
        'status',
        'preferred_locale',
        'last_login_at',
        'disabled_at',
        'disabled_reason',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'status' => AccountStatus::class,
            'preferred_locale' => SupportedLocale::class,
            'last_login_at' => 'datetime',
            'disabled_at' => 'datetime',
        ];
    }

    public function roles(): BelongsToMany
    {
        return $table = $this->belongsToMany(Role::class, 'user_role')
            ->withPivot('assigned_by', 'created_at');
    }

    public function permissionGrants(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'user_permission_grants')
            ->withPivot('granted_by', 'created_at');
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(UserInvitation::class);
    }

    public function hasRole(string $roleKey): bool
    {
        return $this->roles->contains('key', $roleKey);
    }

    public function hasPermission(string $permissionKey): bool
    {
        // Check direct permission grants
        if ($this->permissionGrants->contains('key', $permissionKey)) {
            return true;
        }

        // Check permissions via assigned roles
        foreach ($this->roles as $role) {
            if ($role->permissions->contains('key', $permissionKey)) {
                return true;
            }
        }

        return false;
    }

    public function allPermissions(): array
    {
        $rolePermissions = $this->roles->flatMap->permissions->pluck('key');
        $directGrants = $this->permissionGrants->pluck('key');

        return $rolePermissions->merge($directGrants)->unique()->values()->all();
    }
}
