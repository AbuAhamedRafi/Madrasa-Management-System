<?php

namespace App\Services\AuditTrail;

use App\Repositories\AuditLogRepository;

class AuditLogger
{
    public function __construct(
        protected AuditLogRepository $auditLogRepository
    ) {}

    public function record(
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
    ): void {
        $this->auditLogRepository->log(
            $actionCode,
            $actorUserId,
            $subjectUserId,
            $beforeMetadata,
            $afterMetadata,
            $ipAddress,
            $requestId,
            $reason,
            $entityType,
            $entityId
        );
    }
}

