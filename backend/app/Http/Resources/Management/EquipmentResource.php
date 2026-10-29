<?php

namespace App\Http\Resources\Management;

use App\Models\Equipment;
use App\Models\Personnel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Tier 1 fields ship both the FK id the form binds to (`item_type_id`)
 * and the related row's display name under its historical key (`item`,
 * `brand_manufacturer`, `category`, `classification`,
 * `equipment_condition`). `parent` and `children` only on the create,
 * detail and update endpoints; `transactions` only on the detail endpoint;
 * `original_bundle` on detail and update.
 *
 * @mixin Equipment
 */
class EquipmentResource extends JsonResource
{
    private bool $withOriginalBundle = false;

    /**
     * Adds `original_bundle`, which costs a query and so stays off list responses.
     */
    public function withOriginalBundle(): static
    {
        $this->withOriginalBundle = true;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'property_no' => $this->property_no,
            'old_property_no' => $this->old_property_no,
            'serial_number' => $this->serial_number,
            'item_type_id' => $this->item_type_id,
            'item' => $this->whenLoaded('itemType', fn () => $this->itemType?->name),
            'dashboard_group' => $this->whenLoaded('itemType', fn () => $this->itemType?->dashboard_group?->value),
            'uom' => $this->uom,
            'brand_id' => $this->brand_id,
            'brand_manufacturer' => $this->whenLoaded('brand', fn () => $this->brand?->name),
            'model' => $this->model,
            'specifications' => $this->specifications,
            'non_dcp' => $this->non_dcp,
            'dcp_package' => $this->dcp_package,
            'dcp_year' => $this->dcp_year,
            'equipment_category_id' => $this->equipment_category_id,
            'category' => $this->whenLoaded('equipmentCategory', fn () => $this->equipmentCategory?->name),
            'equipment_classification_id' => $this->equipment_classification_id,
            'classification' => $this->whenLoaded('equipmentClassification', fn () => $this->equipmentClassification?->name),
            'gl_sl_code' => $this->gl_sl_code,
            'uacs' => $this->uacs,
            // Null stays null: "cost not on file yet" is not a zero cost.
            'acquisition_cost' => $this->acquisition_cost !== null ? (float) $this->acquisition_cost : null,
            'received_date' => $this->received_date?->toDateString(),
            'estimated_useful_life' => $this->estimated_useful_life,
            'mode_acquisition' => $this->mode_acquisition,
            'source_acquisition' => $this->source_acquisition,
            'donor' => $this->donor,
            'source_fund' => $this->source_fund,
            'allotment_class' => $this->allotment_class,
            'pm_plan' => $this->pm_plan,
            'equipment_location' => $this->equipment_location,
            'non_functional' => $this->non_functional,
            'equipment_condition_id' => $this->equipment_condition_id,
            'equipment_condition' => $this->whenLoaded('equipmentCondition', fn () => $this->equipmentCondition?->name),
            'disposition_status' => $this->disposition_status,
            'remarks' => $this->remarks,
            'qr_code' => $this->qr_code,
            'under_warranty' => $this->under_warranty,
            'warranty_active' => $this->end_warranty_date === null ? null : $this->isUnderWarranty(),
            'end_warranty_date' => $this->end_warranty_date?->toDateString(),
            'supplier' => $this->supplier,
            'used_by' => $this->used_by,
            'current_accountable_officer' => $this->whenLoaded('currentAccountableOfficer', fn () => self::person($this->currentAccountableOfficer)),
            'current_end_user' => $this->whenLoaded('currentEndUser', fn () => self::person($this->currentEndUser)),
            'parent_equipment_id' => $this->parent_equipment_id,
            'parent' => $this->whenLoaded('parent', fn () => $this->parent ? [
                'id' => $this->parent->id,
                'property_no' => $this->parent->property_no,
            ] : null),
            'children' => $this->whenLoaded('children', fn () => $this->children
                ->sortBy('property_no')
                ->values()
                ->map(fn (Equipment $child) => self::peripheral($child, $request))
                ->all()),
            'original_bundle' => $this->when($this->withOriginalBundle, fn () => self::originalBundle($this->resource, $request)),
            'transactions' => EquipmentTransactionResource::collection($this->whenLoaded('transactions')),
            'activities' => $this->whenLoaded('activities', fn () => $this->activities->map(fn ($activity) => [
                'id' => $activity->id,
                'description' => $activity->description,
                'properties' => $activity->properties,
                'causer' => $activity->causer ? ['id' => $activity->causer->id, 'name' => $activity->causer->name] : null,
                'created_at' => $activity->created_at?->toISOString(),
            ])->all()),
            'attachments' => EquipmentAttachmentResource::collection($this->whenLoaded('attachments')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    /**
     * A peripheral outside the user's data scope shows only its id and property number.
     *
     * @return array<string, mixed>
     */
    private static function peripheral(Equipment $child, Request $request): array
    {
        $user = $request->user();
        $visible = $user !== null && $child->isVisibleTo($user);

        return [
            'id' => $child->id,
            'property_no' => $child->property_no,
            'item' => $visible ? $child->itemType?->name : null,
            'serial_number' => $visible ? $child->serial_number : null,
            'brand_manufacturer' => $visible ? $child->brand?->name : null,
            'model' => $visible ? $child->model : null,
            'equipment_condition' => $visible ? $child->equipmentCondition?->name : null,
        ];
    }

    /**
     * @return array{id: int, property_no: string|null}|null
     */
    private static function originalBundle(Equipment $equipment, Request $request): ?array
    {
        $user = $request->user();
        $bundle = $equipment->originalBundle();

        return $user !== null && $bundle !== null && $bundle->isVisibleTo($user)
            ? ['id' => $bundle->id, 'property_no' => $bundle->property_no]
            : null;
    }

    /**
     * @return array{id: int, full_name: string|null}|null
     */
    public static function person(?Personnel $person): ?array
    {
        return $person ? ['id' => $person->id, 'full_name' => $person->full_name] : null;
    }
}
