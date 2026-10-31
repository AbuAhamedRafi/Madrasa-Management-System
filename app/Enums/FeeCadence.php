<?php

namespace App\Enums;

enum FeeCadence: string
{
    case PER_ENROLLMENT = 'PER_ENROLLMENT';
    case MONTHLY = 'MONTHLY';
    case TERMLY = 'TERMLY';
    case ANNUAL = 'ANNUAL';
    case ONE_TIME = 'ONE_TIME';
}
