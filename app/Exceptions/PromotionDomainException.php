<?php

namespace App\Exceptions;

use Exception;

class PromotionDomainException extends Exception
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 422,
        public readonly array $details = []
    ) {
        parent::__construct($message, $httpStatus);
    }

    public static function ineligible(string $code, string $message, array $details = []): self
    {
        return new self($code, $message, 422, $details);
    }

    public static function conflict(string $code, string $message, array $details = []): self
    {
        return new self($code, $message, 409, $details);
    }

    public static function notFound(string $code, string $message, array $details = []): self
    {
        return new self($code, $message, 404, $details);
    }

    public static function staleVersion(int $currentVersion, int $expectedVersion): self
    {
        return new self(
            'STALE_BATCH_VERSION',
            "Batch lock version mismatch. Expected version {$expectedVersion}, but current is {$currentVersion}.",
            409,
            ['current_version' => $currentVersion, 'expected_version' => $expectedVersion]
        );
    }

    public static function idempotentMismatch(string $key): self
    {
        return new self(
            'IDEMPOTENCY_PAYLOAD_MISMATCH',
            "Idempotency key '{$key}' was previously used with a different request payload.",
            409,
            ['idempotency_key' => $key]
        );
    }
}

