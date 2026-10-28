<?php

namespace App\Repositories;

use App\Models\AuditLog;
use Illuminate\Support\Str;

class AuditLogRepository
{
    public function log(
        string $actionCode,
        ?int $actorUserId,
        ?int $subjectUserId,
        ?array $beforeMetadata = null,
        ?array $afterMetadata = null,
        ?string $ipAddress = null,
        ?string $requestId = null
    ): AuditLog {
        // Scrub sensitive secrets from audit before/after state
        $scrub = fn (?array $data) => $data ? collect($data)->except(['password', 'password_confirmation', 'token', 'token_hash', 'remember_token'])->all() : null;

        return AuditLog::create([
            'id' => (string) Str::uuid(),
            'action_code' => $actionCode,
            'actor_user_id' => $actorUserId,
            'subject_user_id' => $subjectUserId,
            'before_metadata' => $scrub($beforeMetadata),
            'after_metadata' => $scrub($afterMetadata),
            'ip_hash' => $ipAddress ? hash('sha256', $ipAddress) : null,
            'request_id' => $requestId ?? ('req_' . Str::lower((string) Str::ulid())),
            'created_at' => now(),
        ]);
    }
}

