<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OfficeUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('office'));
    }

    /**
     * See OfficeStoreRequest for the office_name uniqueness/school_id
     * nullability rationale — identical here, just `ignore()`d against
     * the office being edited.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'office_name' => [
                'required', 'string', 'max:255',
                Rule::unique('offices', 'office_name')->ignore($this->route('office')),
            ],
            'office_type' => ['nullable', 'string', 'max:100'],
            'school_id' => ['nullable', 'integer'],
            'address' => ['nullable', 'string'],
            'region' => ['nullable', 'string', 'max:100'],
            'district' => ['nullable', 'string', 'max:100'],
            'division' => ['nullable', 'string', 'max:100'],
            'contact_number' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'is_active' => ['boolean'],
        ];
    }
}
