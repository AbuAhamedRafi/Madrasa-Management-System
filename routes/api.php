<?php

use App\Http\Controllers\Authentication\ActivationController;
use App\Http\Controllers\Authentication\SessionController;
use App\Http\Controllers\RolePermission\RolePermissionController;
use App\Http\Controllers\UserManagement\UserController;
use App\Http\Controllers\UserProfile\ProfileController;
use Illuminate\Support\Facades\Route;

// Public auth endpoints
Route::prefix('v1/auth')->group(function () {
    Route::post('/activate', [ActivationController::class, 'activate']);
});

// Authenticated session & self-service profile endpoints
Route::middleware(['auth:sanctum', 'active', 'locale'])->group(function () {
    Route::prefix('v1/me')->group(function () {
        Route::get('/', [ProfileController::class, 'me'])->middleware('permission:profile.read');
        Route::patch('/', [ProfileController::class, 'update'])->middleware('permission:profile.update');
        Route::put('/password', [ProfileController::class, 'changePassword'])->middleware('permission:password.change');
    });

    // Admin user management & role administration
    Route::prefix('v1/admin')->group(function () {
        Route::get('/users', [UserController::class, 'index'])->middleware('permission:users.read');
        Route::post('/users', [UserController::class, 'store'])->middleware('permission:users.create');
        Route::get('/users/{id}', [UserController::class, 'show'])->middleware('permission:users.read');
        Route::patch('/users/{id}', [UserController::class, 'update'])->middleware('permission:users.update');
        Route::patch('/users/{id}/status', [UserController::class, 'updateStatus'])->middleware('permission:users.disable');
        Route::put('/users/{id}/roles', [UserController::class, 'assignRoles'])->middleware('permission:users.assign_role');
        Route::put('/users/{id}/permissions', [UserController::class, 'assignPermissions'])->middleware('permission:users.assign_role');
        Route::post('/users/{id}/invitations', [UserController::class, 'reissueInvitation'])->middleware('permission:users.update');

        Route::get('/roles', [RolePermissionController::class, 'roles'])->middleware('permission:roles.read');
        Route::post('/roles', [RolePermissionController::class, 'storeRole'])->middleware('permission:roles.read');
        Route::get('/permissions', [RolePermissionController::class, 'permissions'])->middleware('permission:roles.read');
        Route::post('/permissions', [RolePermissionController::class, 'storePermission'])->middleware('permission:roles.read');
    });
});
