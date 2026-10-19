<?php

namespace App\Http\Resources\Management;

use App\Enums\Permission as PermissionEnum;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Spatie\Permission\Models\Permission;

/**
 * A seeded permission row, labelled and grouped by App\Enums\Permission
 * (the single source of truth), so the role editor needs no hard-coded
 * catalog of its own.
 *
 * @mixin Permission
 */
class PermissionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $case = PermissionEnum::tryFrom($this->name);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'label' => $case?->label() ?? $this->name,
            'group' => $case?->group() ?? 'Other',
        ];
    }
}
