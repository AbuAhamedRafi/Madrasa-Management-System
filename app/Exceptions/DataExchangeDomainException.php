<?php

namespace App\Exceptions;

use Exception;

class DataExchangeDomainException extends Exception
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 422,
        public readonly array $details = []
    ) {
        parent::__construct($message, $httpStatus);
    }

    public static function fileUnsafe(string $message, array $details = [], int $status = 422): self
    {
        return new self('FILE_UNSAFE', $message, $status, $details);
    }

    public static function templateUnsupported(string $message, array $details = []): self
    {
        return new self('TEMPLATE_VERSION_UNSUPPORTED', $message, 422, $details);
    }

    public static function columnMappingInvalid(string $message, array $details = []): self
    {
        return new self('COLUMN_MAPPING_INVALID', $message, 422, $details);
    }

    public static function duplicateAmbiguous(string $message, array $details = []): self
    {
        return new self('DUPLICATE_AMBIGUOUS', $message, 409, $details);
    }

    public static function academicReferenceInvalid(string $message, array $details = []): self
    {
        return new self('ACADEMIC_REFERENCE_INVALID', $message, 422, $details);
    }

    public static function previewStale(string $message = 'Preview is stale. Reference data or mapping changed.', array $details = []): self
    {
        return new self('PREVIEW_STALE', $message, 409, $details);
    }

    public static function jobStateConflict(string $message, array $details = []): self
    {
        return new self('JOB_STATE_CONFLICT', $message, 409, $details);
    }

    public static function restrictedField(string $message, array $details = []): self
    {
        return new self('RESTRICTED_FIELD', $message, 403, $details);
    }

    public static function schemaViolation(string $message, array $details = []): self
    {
        return new self('SCHEMA_VIOLATION', $message, 422, $details);
    }

    public static function workflowConflict(string $message, array $details = []): self
    {
        return new self('WORKFLOW_CONFLICT', $message, 409, $details);
    }

    public static function fileExpired(string $message = 'Export artifact has expired and is no longer available.', array $details = []): self
    {
        return new self('FILE_EXPIRED', $message, 410, $details);
    }

    public static function notFound(string $code = 'DATA_JOB_NOT_FOUND', string $message = 'Data job not found.', array $details = []): self
    {
        return new self($code, $message, 404, $details);
    }

    public static function forbidden(string $code = 'FORBIDDEN', string $message = 'You do not have access to this resource.', array $details = []): self
    {
        return new self($code, $message, 403, $details);
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
            'JOB_STATE_CONFLICT',
            "Request with Idempotency key '{$key}' is currently in flight.",
            409,
            ['idempotency_key' => $key]
        );
    }
}
