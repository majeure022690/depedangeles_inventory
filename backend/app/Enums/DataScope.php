<?php

namespace App\Enums;

enum DataScope: string
{
    case Own = 'own';
    case School = 'school';
    case All = 'all';

    public function label(): string
    {
        return match ($this) {
            self::Own => 'Own records only',
            self::School => 'Own school',
            self::All => 'All records',
        };
    }

    public function rank(): int
    {
        return match ($this) {
            self::Own => 0,
            self::School => 1,
            self::All => 2,
        };
    }
}
