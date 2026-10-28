<?php

namespace App\Enums;

enum SystemRole: string
{
    case ADMIN = 'ADMIN';
    case OFFICE_STAFF = 'OFFICE_STAFF';
    case ACCOUNTS = 'ACCOUNTS';

    public function labelEn(): string
    {
        return match ($this) {
            self::ADMIN => 'Admin',
            self::OFFICE_STAFF => 'Office Staff',
            self::ACCOUNTS => 'Accounts',
        };
    }

    public function labelBn(): string
    {
        return match ($this) {
            self::ADMIN => 'অ্যাডমিন',
            self::OFFICE_STAFF => 'অফিস স্টাফ',
            self::ACCOUNTS => 'হিসাব শাখা',
        };
    }
}

