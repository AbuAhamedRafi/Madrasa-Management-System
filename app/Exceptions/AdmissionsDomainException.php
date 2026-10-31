<?php

namespace App\Exceptions;

use Exception;

class AdmissionsDomainException extends Exception
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 422,
        public readonly array $details = []
    ) {
        parent::__construct($message, $httpStatus);
    }

    public static function noSeatsAvailable(int $sectionId, int $capacity): self
    {
        return new self(
            'NO_SEATS_AVAILABLE',
            "No seats available in section #{$sectionId}. Section capacity of {$capacity} has been reached.",
            409,
            ['section_id' => $sectionId, 'capacity' => $capacity]
        );
    }

    public static function ineligible(string $code, string $message, array $details = []): self
    {
        return new self($code, $message, 422, $details);
    }

    public static function invalidState(string $message, int $httpStatus = 409): self
    {
        return new self('INVALID_STATE', $message, $httpStatus);
    }
}

