<?php

namespace App\Enums;

enum DataScope: string
{
    case Own = 'own';
    case School = 'school';
    case All = 'all';

    public function rank(): int
    {
        return match ($this) {
            self::Own => 0,
            self::School => 1,
            self::All => 2,
        };
    }
}
