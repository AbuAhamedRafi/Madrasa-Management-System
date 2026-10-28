<?php

namespace App\Services\UserManagement;

use App\Enums\AccountStatus;
use App\Models\User;
use App\Repositories\RolePermissionRepository;
use App\Repositories\UserRepository;
use App\Repositories\UserSessionRepository;
use App\Services\AuditTrail\AuditLogger;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class AccountStatusService
{
    public function __construct(
        protected UserRepository $userRepository,
        protected UserSessionRepository $userSessionRepository,
        protected AuditLogger $auditLogger
    ) {}

    public function updateStatus(
        User $targetUser,
        AccountStatus $newStatus,
        ?string $reason,
        User $actor,
        ?string $ip = null,
        ?string $requestId = null
    ): User {
        return DB::transaction(function () use ($targetUser, $newStatus, $reason, $actor, $ip, $requestId) {
            // Last-active-admin guard: block disabling the last active admin
            if ($newStatus === AccountStatus::DISABLED && $targetUser->hasRole('ADMIN')) {
                $otherAdminsCount = $this->userRepository->countActiveAdminsExcluding($targetUser->id);
                if ($otherAdminsCount === 0) {
                    throw new ConflictHttpException('LAST_ADMIN_PROTECTED: Cannot disable the last active administrator.');
                }
            }

            $beforeState = ['status' => $targetUser->status->value];

            $updateData = [
                'status' => $newStatus,
                'disabled_at' => $newStatus === AccountStatus::DISABLED ? now() : null,
                'disabled_reason' => $newStatus === AccountStatus::DISABLED ? $reason : null,
            ];

            $this->userRepository->update($targetUser, $updateData);

            // Immediately revoke sessions when disabling
            if ($newStatus === AccountStatus::DISABLED) {
                $this->userSessionRepository->revokeAllForUser($targetUser->id);
            }

            $this->auditLogger->record(
                'USER_STATUS_CHANGED',
                $actor->id,
                $targetUser->id,
                $beforeState,
                ['status' => $newStatus->value, 'reason' => $reason],
                $ip,
                $requestId
            );

            return $targetUser->fresh(['roles.permissions']);
        });
    }
}

