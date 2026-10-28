<?php

namespace App\Services\Authentication;

use App\Enums\AccountStatus;
use App\Models\User;
use App\Repositories\UserInvitationRepository;
use App\Repositories\UserRepository;
use App\Services\AuditTrail\AuditLogger;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class AccountActivationService
{
    public function __construct(
        protected UserInvitationRepository $invitationRepository,
        protected UserRepository $userRepository,
        protected AuditLogger $auditLogger
    ) {}

    public function activate(
        string $rawToken,
        string $newPassword,
        ?string $ip = null,
        ?string $requestId = null
    ): User {
        $tokenHash = hash('sha256', $rawToken);
        $invitation = $this->invitationRepository->findValidByTokenHash($tokenHash);

        if (! $invitation) {
            throw new UnprocessableEntityHttpException('TOKEN_INVALID: The invitation token is invalid.');
        }

        if ($invitation->consumed_at !== null) {
            throw new ConflictHttpException('TOKEN_USED: This invitation token has already been used.');
        }

        if ($invitation->revoked_at !== null || $invitation->expires_at->isPast()) {
            throw new UnprocessableEntityHttpException('TOKEN_EXPIRED: This invitation has expired or was revoked.');
        }

        return DB::transaction(function () use ($invitation, $newPassword, $ip, $requestId) {
            $user = $invitation->user;

            $this->invitationRepository->consume($invitation);

            $this->userRepository->update($user, [
                'password' => $newPassword,
                'status' => AccountStatus::ACTIVE,
            ]);

            $this->auditLogger->record(
                'USER_ACTIVATED',
                $user->id,
                $user->id,
                ['status' => 'INVITED'],
                ['status' => 'ACTIVE'],
                $ip,
                $requestId
            );

            return $user->fresh();
        });
    }
}

