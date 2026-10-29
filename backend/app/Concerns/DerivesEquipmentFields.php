<?php

namespace App\Concerns;

use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\ItemType;

/**
 * Fills blank equipment fields on every save so all write paths agree.
 * Category and GL-SL code follow the acquisition cost on create, and on
 * update only when the cost changes.
 */
trait DerivesEquipmentFields
{
    public const string HIGH_VALUE_CATEGORY = 'High-value';

    public const string LOW_VALUE_CATEGORY = 'Low-value';

    public const string HIGH_VALUE_GL_SL_CODE = '10605030';

    public const string LOW_VALUE_GL_SL_CODE = '10405030';

    public const string BUNDLED_UOM = 'Set (bundled)';

    public const string DEFAULT_UOM = 'Piece';

    public const string DEFAULT_ALLOTMENT_CLASS = 'Not Applicable';

    /**
     * @var array<string, string>
     */
    private const array CLASSIFICATION_DEFAULTS = [
        'uacs' => 'default_uacs',
        'estimated_useful_life' => 'default_useful_life',
    ];

    protected static function bootDerivesEquipmentFields(): void
    {
        static::saving(function (Equipment $equipment): void {
            if (! $equipment->exists) {
                $equipment->deriveCostFields(onlyBlank: true);
            } elseif ($equipment->isDirty('acquisition_cost')) {
                $equipment->deriveCostFields(onlyBlank: false);
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

    public static function glSlCodeForCategory(string $category): string
    {
        return $category === self::HIGH_VALUE_CATEGORY ? self::HIGH_VALUE_GL_SL_CODE : self::LOW_VALUE_GL_SL_CODE;
    }

    /**
     * @return array{threshold: int, low: array{category: string, gl_sl_code: string}, high: array{category: string, gl_sl_code: string}}
     */
    public static function categoryRules(): array
    {
        return [
            'threshold' => Equipment::PPE_THRESHOLD,
            'low' => ['category' => self::LOW_VALUE_CATEGORY, 'gl_sl_code' => self::LOW_VALUE_GL_SL_CODE],
            'high' => ['category' => self::HIGH_VALUE_CATEGORY, 'gl_sl_code' => self::HIGH_VALUE_GL_SL_CODE],
        ];
    }

    private function deriveCostFields(bool $onlyBlank): void
    {
        $name = self::categoryNameForCost($this->acquisition_cost);

        if (! $onlyBlank || $this->getAttribute('equipment_category_id') === null) {
            $category = EquipmentCategory::query()->where('name', $name)->first(['id', 'name']);

            if ($category !== null) {
                $this->equipmentCategory()->associate($category);
            }
        }

        if (! $onlyBlank || blank($this->gl_sl_code)) {
            $this->gl_sl_code = self::glSlCodeForCategory($name);
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
