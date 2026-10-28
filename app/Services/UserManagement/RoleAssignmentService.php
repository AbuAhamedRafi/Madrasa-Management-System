<?php

namespace App\Services\UserManagement;

use App\Models\User;
use App\Repositories\RolePermissionRepository;
use App\Repositories\UserRepository;
use App\Repositories\UserSessionRepository;
use App\Services\AuditTrail\AuditLogger;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class RoleAssignmentService
{
    public function __construct(
        protected UserRepository $userRepository,
        protected RolePermissionRepository $rolePermissionRepository,
        protected UserSessionRepository $userSessionRepository,
        protected AuditLogger $auditLogger
    ) {}

    public function assignRoles(
        User $targetUser,
        array $roleKeys,
        User $actor,
        ?string $ip = null,
        ?string $requestId = null
    ): User {
        return DB::transaction(function () use ($targetUser, $roleKeys, $actor, $ip, $requestId) {
            $currentRoleKeys = $targetUser->roles->pluck('key')->all();

            // Last-active-admin guard: block removing ADMIN role from the last active admin
            if (in_array('ADMIN', $currentRoleKeys) && ! in_array('ADMIN', $roleKeys)) {
                $otherAdminsCount = $this->userRepository->countActiveAdminsExcluding($targetUser->id);
                if ($otherAdminsCount === 0) {
                    throw new ConflictHttpException('LAST_ADMIN_PROTECTED: Cannot remove the Admin role from the last active administrator.');
                }
            }

            $roleIds = [];
            foreach ($roleKeys as $key) {
                $role = $this->rolePermissionRepository->findRoleByKey($key);
                if ($role) {
                    $roleIds[$role->id] = ['assigned_by' => $actor->id, 'created_at' => now()];
                }
            }

            $targetUser->roles()->sync($roleIds);

            // Invalidate existing sessions so permissions are reloaded on next login
            $this->userSessionRepository->revokeAllForUser($targetUser->id);

            $this->auditLogger->record(
                'USER_ROLE_CHANGED',
                $actor->id,
                $targetUser->id,
                ['roles' => $currentRoleKeys],
                ['roles' => $roleKeys],
                $ip,
                $requestId
            );

            return $targetUser->fresh(['roles.permissions']);
        });
    }
}

