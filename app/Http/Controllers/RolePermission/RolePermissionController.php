<?php

namespace App\Http\Controllers\RolePermission;

use App\Http\Controllers\Controller;
use App\Http\Resources\RolePermission\PermissionResource;
use App\Http\Resources\RolePermission\RoleResource;
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
}

