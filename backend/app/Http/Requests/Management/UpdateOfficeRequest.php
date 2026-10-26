<?php

declare(strict_types=1);

namespace App\Http\Requests\Management;

use App\Models\Office;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateOfficeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('office'));
    }

    /**
     * See StoreOfficeRequest for the office_name uniqueness/school_id
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

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $office = $this->route('office');

            if (! $office instanceof Office || ! $office->isManagedByPrime() || $validator->errors()->has('office_name')) {
                return;
            }

            if (trim($this->string('office_name')->toString()) !== trim((string) $office->office_name)) {
                $validator->errors()->add('office_name', __('Managed by PRIME.'));
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return (new StoreOfficeRequest)->attributes();
    }
}
