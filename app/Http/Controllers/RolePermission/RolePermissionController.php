<?php

namespace App\Http\Controllers\RolePermission;

use App\Http\Controllers\Controller;
use App\Http\Requests\RolePermission\CreatePermissionRequest;
use App\Http\Requests\RolePermission\CreateRoleRequest;
use App\Http\Resources\RolePermission\PermissionResource;
use App\Http\Resources\RolePermission\RoleResource;
use App\Models\Permission;
use App\Models\Role;
use App\Repositories\RolePermissionRepository;
use Illuminate\Http\JsonResponse;

class RolePermissionController extends Controller
{
    public function __construct(
        protected RolePermissionRepository $rolePermissionRepository
    ) {}

    public function roles(): JsonResponse
    {
        $roles = $this->rolePermissionRepository->allRolesWithPermissions();

        return response()->json([
            'data' => RoleResource::collection($roles),
        ]);
    }

    public function permissions(): JsonResponse
    {
        $permissions = $this->rolePermissionRepository->allPermissions();

        return response()->json([
            'data' => PermissionResource::collection($permissions),
        ]);
    }

    public function storeRole(CreateRoleRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $permissions = $validated['permissions'] ?? [];
        unset($validated['permissions']);

        $validated['is_system'] = false;
        $role = Role::create($validated);

        if (! empty($permissions)) {
            $permissionIds = Permission::whereIn('key', $permissions)->pluck('id');
            $role->permissions()->sync($permissionIds);
        }

        return response()->json([
            'data' => new RoleResource($role->load('permissions')),
        ], 201);
    }

    public function storePermission(CreatePermissionRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $maxOrder = Permission::max('display_order') ?? 0;
        $validated['display_order'] = $maxOrder + 1;

        $permission = Permission::create($validated);

        return response()->json([
            'data' => new PermissionResource($permission),
        ], 201);
    }
}

