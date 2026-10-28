<?php

declare(strict_types=1);

namespace App\Http\Requests\Management;

use App\Http\Requests\Management\Concerns\ValidatesDesktopBundles;
use App\Models\Equipment;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class LinkEquipmentPeripheralRequest extends FormRequest
{
    use ValidatesDesktopBundles;

    public function authorize(): Response
    {
        $gate = Gate::forUser($this->user());
        $update = $gate->inspect('update', $this->route('equipment'));

        return $update->denied() ? $update : $gate->inspect('createTransaction', $this->route('equipment'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'equipment_id' => ['required', 'integer', Rule::exists('equipment', 'id')->withoutTrashed()],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty() || ! $this->ensureBundleParent($validator, needsPropertyNo: false)) {
                return;
            }

            $error = $this->peripheralError($this->peripheral());

            if ($error !== null) {
                $validator->errors()->add('equipment_id', $error);
            }
        }];
    }

    public function peripheral(): Equipment
    {
        return Equipment::with(['itemType', 'parent:id,property_no', 'createdBy:id,office_id'])->findOrFail($this->integer('equipment_id'));
    }

    private function peripheralError(Equipment $peripheral): ?string
    {
        if (! $peripheral->isVisibleTo($this->user())) {
            return __('The selected equipment is invalid.');
        }

        if (! $peripheral->isPeripheral()) {
            return __('Only a Monitor, Keyboard or Mouse can be linked to a desktop.');
        }

        if ($peripheral->parent !== null) {
            return __('This peripheral is already linked to desktop :property_no.', ['property_no' => $peripheral->parent->property_no]);
        }

        if ($peripheral->children()->exists()) {
            return __('Equipment with its own peripherals cannot be linked.');
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['equipment_id' => 'equipment'];
    }
}
