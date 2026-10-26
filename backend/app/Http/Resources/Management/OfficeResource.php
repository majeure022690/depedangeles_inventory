<?php

namespace App\Http\Resources\Management;

use App\Models\Office;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Office
 */
class OfficeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'office_name' => $this->office_name,
            'office_type' => $this->office_type,
            'school_id' => $this->school_id,
            'address' => $this->address,
            'region' => $this->region,
            'district' => $this->district,
            'division' => $this->division,
            'contact_number' => $this->contact_number,
            'email' => $this->email,
            'is_active' => $this->is_active,
            'prime_department_id' => $this->prime_department_id,
            'managed_by_prime' => $this->isManagedByPrime(),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
