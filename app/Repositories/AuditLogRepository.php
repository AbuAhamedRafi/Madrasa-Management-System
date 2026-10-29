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
        ?string $requestId = null,
        ?string $reason = null,
        ?string $entityType = null,
        ?int $entityId = null
    ): AuditLog {
        // Scrub sensitive secrets from audit before/after state
        $scrub = fn (?array $data) => $data ? collect($data)->except(['password', 'password_confirmation', 'token', 'token_hash', 'remember_token'])->all() : null;

        return AuditLog::create([
            'id' => (string) Str::uuid(),
            'action_code' => $actionCode,
            'actor_user_id' => $actorUserId,
            'subject_user_id' => $subjectUserId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'before_metadata' => $scrub($beforeMetadata),
            'after_metadata' => $scrub($afterMetadata),
            'reason' => $reason,
            'ip_hash' => $ipAddress ? hash('sha256', $ipAddress) : null,
            'request_id' => $requestId ?? ('req_' . Str::lower((string) Str::ulid())),
            'created_at' => now(),
        ]);
    }

    public function getEntityHistory(string $entityType, int $entityId, int $limit = 30)
    {
        return AuditLog::with('actor:id,public_id,username,name_bn,name_en')
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }
}

