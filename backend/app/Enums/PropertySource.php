<?php

namespace App\Enums;

/**
 * The RPCPPE "Transferred / donations by" columns.
 */
enum PropertySource: string
{
    case Division = 'division';
    case RegionalCentral = 'regional_central';
    case Lgu = 'lgu';
    case PtaOthers = 'pta_others';

    public function label(): string
    {
        return match ($this) {
            self::Division => 'Division',
            self::RegionalCentral => 'Regional / Central',
            self::Lgu => 'LGU',
            self::PtaOthers => 'PTA / Others',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $source) => ['value' => $source->value, 'label' => $source->label()], self::cases());
    }
}
