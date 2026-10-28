<?php

declare(strict_types=1);

namespace App\Http\Requests\Management;

class StoreEquipmentPeripheralBatchRequest extends StoreEquipmentPeripheralRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->peripheralRowsRules(required: true);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->peripheralRowsMessages();
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return $this->peripheralRowsAttributes();
    }
}
