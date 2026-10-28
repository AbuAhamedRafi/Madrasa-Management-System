<?php

namespace App\Services\UserProfile;

use App\Models\User;
use App\Repositories\UserRepository;
use App\Repositories\UserSessionRepository;
use App\Services\AuditTrail\AuditLogger;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class PasswordChangeService
{
    public function __construct(
        protected UserRepository $userRepository,
        protected UserSessionRepository $userSessionRepository,
        protected AuditLogger $auditLogger
    ) {}

    public function changePassword(
        User $user,
        string $currentPassword,
        string $newPassword,
        ?string $currentSessionId = null,
        ?string $ip = null,
        ?string $requestId = null
    ): void {
        if (! Hash::check($currentPassword, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['The current password provided is incorrect.'],
            ]);
        }

        $this->userRepository->update($user, [
            'password' => $newPassword,
        ]);

        // Revoke all other active sessions for security
        $this->userSessionRepository->revokeAllForUser($user->id, $currentSessionId);

        $this->auditLogger->record(
            'PASSWORD_CHANGED',
            $user->id,
            $user->id,
            null,
            null,
            $ip,
            $requestId
        );
    }
}

