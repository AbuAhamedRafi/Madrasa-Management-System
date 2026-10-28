<?php

namespace App\Repositories;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Eloquent\Collection;

class RolePermissionRepository
{
    public function allRolesWithPermissions(): Collection
    {
        return Role::with('permissions')->get();
    }

    public function allPermissions(): Collection
    {
        return Permission::orderBy('display_order')->get();
    }

    public function findRoleByKey(string $key): ?Role
    {
        return Role::where('key', $key)->first();
    }
}

