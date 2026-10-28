<?php

namespace App\Repositories;

use Illuminate\Support\Facades\DB;

class UserSessionRepository
{
    /**
     * Revoke all active sessions for a given user from the PostgreSQL sessions table.
     */
    public function revokeAllForUser(int $userId, ?string $exceptSessionId = null): int
    {
        $query = DB::table('sessions')->where('user_id', $userId);

        if ($exceptSessionId) {
            $query->where('id', '!=', $exceptSessionId);
        }

        return $query->delete();
    }
}

