<?php

namespace App\Services\Authentication;

use App\Repositories\UserInvitationRepository;
use Illuminate\Support\Carbon;

class InvitationService
{
    public function __construct(
        protected UserInvitationRepository $invitationRepository
    ) {}

    /**
     * Generates a cryptographically secure 256-bit token, saves its SHA-256 hash, and returns the raw token once.
     */
    public function generateToken(int $userId, ?int $createdBy = null, int $ttlMinutes = 30): array
    {
        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);
        $expiresAt = Carbon::now()->addMinutes($ttlMinutes);

        $invitation = $this->invitationRepository->createInvitation($userId, $tokenHash, $expiresAt, $createdBy);

        return [
            'raw_token' => $rawToken,
            'expires_at' => $expiresAt->toIso8601String(),
            'invitation_id' => $invitation->id,
        ];
    }
}

