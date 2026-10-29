<?php

namespace App\Exceptions;

use Exception;

class AcademicDomainException extends Exception
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 409,
        public readonly array $details = []
    ) {
        parent::__construct($message, $httpStatus);
    }
}

