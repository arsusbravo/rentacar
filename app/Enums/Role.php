<?php

namespace App\Enums;

enum Role: string
{
    case Admin = 'admin';
    case Employee = 'employee';
    case Client = 'client';

    /** Roles that may use the /admin back office. */
    public static function staff(): array
    {
        return [self::Admin, self::Employee];
    }
}
