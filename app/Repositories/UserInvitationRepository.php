<?php

namespace App\Repositories;

use App\Models\UserInvitation;
use Illuminate\Support\Carbon;

class UserInvitationRepository
{
    public function createInvitation(int $userId, string $tokenHash, Carbon $expiresAt, ?int $createdBy = null): UserInvitation
    {
        // Invalidate prior active invitations for the user
        UserInvitation::where('user_id', $userId)
            ->whereNull('consumed_at')
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);

        return UserInvitation::create([
            'user_id' => $userId,
            'token_hash' => $tokenHash,
            'expires_at' => $expiresAt,
            'delivery_status' => 'MANUAL_HANDOFF',
            'created_by' => $createdBy,
        ]);
    }

    public function findValidByTokenHash(string $tokenHash): ?UserInvitation
    {
        return UserInvitation::with('user')
            ->where('token_hash', $tokenHash)
            ->first();
    }

    public function consume(UserInvitation $invitation): bool
    {
        return $invitation->update(['consumed_at' => now()]);
    }
}

