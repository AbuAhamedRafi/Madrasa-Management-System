<?php

namespace App\Services\AuditTrail;

class AuditLogService
{
    public function __construct(
        protected AuditLogger $logger
    ) {}

    public function log(string $action, ?int $actorId, ?string $targetType = null, ?int $targetId = null, ?array $payload = null): void
    {
        $this->logger->record(
            actionCode: $action,
            actorUserId: $actorId,
            subjectUserId: null,
            beforeMetadata: null,
            afterMetadata: $payload,
            ipAddress: request()?->ip(),
            requestId: request()?->header('X-Request-Id'),
            reason: null,
            entityType: $targetType,
            entityId: $targetId
        );
    }
}

