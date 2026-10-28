<?php

namespace App\Http\Requests\Management\Concerns;

use App\Models\Equipment;
use Illuminate\Validation\Validator;

trait ValidatesDesktopBundles
{
    protected function bundleParent(): Equipment
    {
        /** @var Equipment $parent */
        $parent = $this->route('equipment');
        $parent->loadMissing('itemType');

        return $parent;
    }

    /**
     * Fails validation unless the routed equipment may carry peripherals.
     */
    protected function ensureBundleParent(Validator $validator, bool $needsPropertyNo): bool
    {
        $parent = $this->bundleParent();

        if (! $parent->canHavePeripherals()) {
            $validator->errors()->add('equipment', __('Only a Desktop (package) or CPU that is not itself linked can have peripherals.'));

            return false;
        }

        if ($needsPropertyNo && blank($parent->property_no)) {
            $validator->errors()->add('equipment', __('Give the desktop a property number before adding peripherals.'));

            return false;
        }

        return true;
    }
}
