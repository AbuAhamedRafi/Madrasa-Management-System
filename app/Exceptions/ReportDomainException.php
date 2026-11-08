<?php

namespace App\Exceptions;

use Exception;

class ReportDomainException extends Exception
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 422,
        public readonly array $details = []
    ) {
        parent::__construct($message, $httpStatus);
    }

    public static function forbidden(string $code = 'FORBIDDEN', string $message = 'You do not have access to this reporting resource.', array $details = []): self
    {
        return new self($code, $message, 403, $details);
    }

    public static function forbiddenScope(string $message = 'Requested scope is outside your authorized organizational grant.', array $details = []): self
    {
        return new self('FORBIDDEN_SCOPE', $message, 403, $details);
    }

    public static function notFound(string $code = 'REPORT_NOT_FOUND', string $message = 'Reporting resource not found.', array $details = []): self
    {
        return new self($code, $message, 404, $details);
    }

    public static function versionConflict(string $message = 'Saved view was modified by another session.', array $details = []): self
    {
        return new self('VIEW_VERSION_CONFLICT', $message, 409, $details);
    }

    public static function validationError(string $message, array $details = []): self
    {
        return new self('INVALID_REPORT_QUERY', $message, 422, $details);
    }
}
