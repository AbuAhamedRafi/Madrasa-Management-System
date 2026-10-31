<?php

namespace App\Exceptions;

use Exception;

class BillingDomainException extends Exception
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 422,
        public readonly array $details = []
    ) {
        parent::__construct($message, $httpStatus);
    }

    public static function validation(string $code, string $message, array $details = []): self
    {
        return new self($code, $message, 422, $details);
    }

    public static function conflict(string $code, string $message, array $details = []): self
    {
        return new self($code, $message, 409, $details);
    }

    public static function forbidden(string $code, string $message, array $details = []): self
    {
        return new self($code, $message, 403, $details);
    }

    public static function notFound(string $code, string $message, array $details = []): self
    {
        return new self($code, $message, 404, $details);
    }

    public static function staleVersion(int $currentVersion, int $expectedVersion): self
    {
        return new self(
            'STALE_LOCK_VERSION',
            "Lock version mismatch. Expected version {$expectedVersion}, but current is {$currentVersion}.",
            409,
            ['current_version' => $currentVersion, 'expected_version' => $expectedVersion]
        );
    }

    public static function idempotentMismatch(string $key): self
    {
        return new self(
            'IDEMPOTENCY_PAYLOAD_MISMATCH',
            "Idempotency key '{$key}' was previously used with a different request payload.",
            422,
            ['idempotency_key' => $key]
        );
    }

    public static function idempotentInFlight(string $key): self
    {
        return new self(
            'IDEMPOTENCY_IN_FLIGHT',
            "Request with Idempotency key '{$key}' is currently in flight.",
            409,
            ['idempotency_key' => $key]
        );
    }
}
