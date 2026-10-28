<?php

namespace App\Enums;

enum AccountStatus: string
{
    case INVITED = 'INVITED';
    case ACTIVE = 'ACTIVE';
    case DISABLED = 'DISABLED';

    public function canSignIn(): bool
    {
        return $this === self::ACTIVE;
    }
}

