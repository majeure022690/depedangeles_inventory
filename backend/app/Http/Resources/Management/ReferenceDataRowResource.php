<?php

namespace App\Http\Resources\Management;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of any of the 13 reference-data tables. Tier 1 rows carry
 * `name`; Tier 2 (library) rows carry `type` + `value` instead.
 *
 * @property Model $resource
 */
class ReferenceDataRowResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $row = $this->resource;
        $isLibrary = $row->isFillable('type');
        $type = $row->getAttribute('type');

        return [
            'id' => $row->getKey(),
            ...($isLibrary ? [
                'type' => $type instanceof BackedEnum ? $type->value : $type,
                'value' => $row->getAttribute('value'),
            ] : [
                'name' => $row->getAttribute('name'),
            ]),
            'description' => $row->getAttribute('description'),
            'sort_order' => $row->getAttribute('sort_order'),
            'is_active' => $row->getAttribute('is_active'),
        ];
    }
}
