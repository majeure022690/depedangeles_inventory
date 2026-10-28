<?php

namespace App\Concerns;

use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\ItemType;

/**
 * Fills blank equipment fields on every save so all write paths agree.
 */
trait DerivesEquipmentFields
{
    public const string HIGH_VALUE_CATEGORY = 'High-value';

    public const string LOW_VALUE_CATEGORY = 'Low-value';

    public const string BUNDLED_UOM = 'Set (bundled)';

    public const string DEFAULT_UOM = 'Piece';

    public const string DEFAULT_ALLOTMENT_CLASS = 'Not Applicable';

    /**
     * @var array<string, string>
     */
    private const array CLASSIFICATION_DEFAULTS = [
        'gl_sl_code' => 'default_gl_sl_code',
        'uacs' => 'default_uacs',
        'estimated_useful_life' => 'default_useful_life',
    ];

    protected static function bootDerivesEquipmentFields(): void
    {
        static::saving(function (Equipment $equipment): void {
            if ($equipment->getAttribute('equipment_category_id') === null) {
                $equipment->deriveCategory();
            }

            if ($equipment->getAttribute('under_warranty') === null) {
                $equipment->under_warranty = $equipment->isUnderWarranty();
            }

            $equipment->applyClassificationDefaults();

            if (blank($equipment->uom)) {
                $equipment->uom = $equipment->itemType()->value('name') === ItemType::DESKTOP_PACKAGE_NAME
                    ? self::BUNDLED_UOM
                    : self::DEFAULT_UOM;
            }

            if (blank($equipment->allotment_class)) {
                $equipment->allotment_class = self::DEFAULT_ALLOTMENT_CLASS;
            }
        });
    }

    public function isUnderWarranty(): bool
    {
        return $this->end_warranty_date !== null && $this->end_warranty_date->greaterThanOrEqualTo(today());
    }

    public static function categoryNameForCost(mixed $cost): string
    {
        return $cost !== null && $cost !== '' && (float) $cost >= Equipment::PPE_THRESHOLD
            ? self::HIGH_VALUE_CATEGORY
            : self::LOW_VALUE_CATEGORY;
    }

    private function deriveCategory(): void
    {
        $category = EquipmentCategory::query()
            ->where('name', self::categoryNameForCost($this->acquisition_cost))
            ->first(['id', 'name']);

        if ($category !== null) {
            $this->equipmentCategory()->associate($category);
        }
    }

    private function applyClassificationDefaults(): void
    {
        $blank = array_filter(array_keys(self::CLASSIFICATION_DEFAULTS), fn (string $column) => blank($this->getAttribute($column)));

        if ($blank === []) {
            return;
        }

        $classification = $this->equipmentClassification()->first();

        if ($classification === null) {
            return;
        }

        foreach ($blank as $column) {
            $this->setAttribute($column, $classification->getAttribute(self::CLASSIFICATION_DEFAULTS[$column]));
        }
    }
}
