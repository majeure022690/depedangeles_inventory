<?php

namespace App\Enums;

use App\Models\ItemType;

enum DashboardEquipmentGroup: string
{
    case DesktopComputer = 'desktop_computer';
    case Laptop = 'laptop';
    case Tablet = 'tablet';
    case Printer = 'printer';
    case Tv = 'tv';
    case ProjectorSmartboard = 'projector_smartboard';
    case Smartphone = 'smartphone';
    case Server = 'server';
    case Copier = 'copier';
    case NetworkSwitch = 'network_switch';

    public function label(): string
    {
        return match ($this) {
            self::DesktopComputer => 'Desktop Computer',
            self::Laptop => 'Laptop',
            self::Tablet => 'Tablet',
            self::Printer => 'Printer',
            self::Tv => 'TV',
            self::ProjectorSmartboard => 'Projector / Smartboard',
            self::Smartphone => 'Smartphone',
            self::Server => 'Server',
            self::Copier => 'Copier / Xerox Machine',
            self::NetworkSwitch => 'Network Switch',
        };
    }

    /**
     * Breakdown label => the item type names counted under it.
     *
     * @return array<string, list<string>>
     */
    public function breakdown(): array
    {
        return match ($this) {
            self::DesktopComputer => [
                'System Unit' => ItemType::BUNDLE_PARENT_NAMES,
                'Monitor' => ['Monitor'],
                'Keyboard' => ['Keyboard'],
                'Mouse' => ['Mouse'],
            ],
            self::Tablet => ['Tablet' => ['Tablet'], '2-in-1 Tablet' => ['2-in-1 Tablet']],
            self::Printer => ['Printer' => ['Printer'], 'Multifunction Printer' => ['Multifunction Printer']],
            self::Tv => ['Standard TV' => ['Standard TV'], 'Smart TV' => ['Smart TV']],
            self::ProjectorSmartboard => ['Projector' => ['Projector'], 'Smartboard' => ['Interactive Whiteboards / Smartboards']],
            self::Server => ['Server (Bundle)' => ['Server (Bundle)'], 'Server (System Unit)' => ['Server (System Unit)']],
            default => [],
        };
    }

    /**
     * Item type names the headline count is limited to; null counts every item type in the group.
     *
     * @return list<string>|null
     */
    public function headlineItemTypes(): ?array
    {
        return $this === self::DesktopComputer ? ItemType::BUNDLE_PARENT_NAMES : null;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $group) => ['value' => $group->value, 'label' => $group->label()], self::cases());
    }
}
