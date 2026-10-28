<?php

namespace App\Models;

use App\Concerns\HasActiveReferenceOptions;
use App\Concerns\LogsReferenceDataActivity;
use App\Enums\DashboardEquipmentGroup;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Tier 1 reference-data entity (lookup-normalization ADR).
 *
 * @property DashboardEquipmentGroup|null $dashboard_group
 */
#[Fillable(['name', 'description', 'dashboard_group', 'sort_order', 'is_active'])]
class ItemType extends Model
{
    use HasActiveReferenceOptions, LogsReferenceDataActivity;

    public const string DESKTOP_PACKAGE_NAME = 'Desktop (package)';

    /**
     * Item types that may carry linked peripherals (a desktop bundle's system unit).
     */
    public const array BUNDLE_PARENT_NAMES = [self::DESKTOP_PACKAGE_NAME, 'CPU'];

    /**
     * Item types that may be linked to a desktop bundle.
     */
    public const array PERIPHERAL_NAMES = ['Monitor', 'Keyboard', 'Mouse'];

    protected function casts(): array
    {
        return [
            'dashboard_group' => DashboardEquipmentGroup::class,
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function isBundleParent(): bool
    {
        return in_array($this->name, self::BUNDLE_PARENT_NAMES, true);
    }

    public function isPeripheral(): bool
    {
        return in_array($this->name, self::PERIPHERAL_NAMES, true);
    }
}
