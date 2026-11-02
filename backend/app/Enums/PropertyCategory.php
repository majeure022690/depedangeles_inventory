<?php

namespace App\Enums;

/**
 * Article suggestions follow the division's RPCPPE workbook list.
 */
enum PropertyCategory: string
{
    case ItEquipment = 'it_equipment';

    public function label(): string
    {
        return match ($this) {
            self::ItEquipment => 'IT Equipment',
        };
    }

    /**
     * @return list<string>
     */
    public function articles(): array
    {
        return match ($this) {
            self::ItEquipment => ['AVR', 'Biometric Scanner', 'CCTV System', 'Desktop Computer', 'External DVD Drive', 'External Hard Drive', 'Interactive Whiteboard', 'Keyboard', 'Laptop Computer', 'Licensed Software', 'Monitor', 'Mouse', 'Multimedia Speaker', 'Netbook', 'Network Hardware (Router, Switch etc.)', 'Pocket WiFi', 'Printer', 'Projector', 'Projector Screen', 'Scanner', 'Television', 'UPS', 'USB Hub', 'Other'],
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $category) => ['value' => $category->value, 'label' => $category->label()], self::cases());
    }
}
