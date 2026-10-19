<?php

declare(strict_types=1);

namespace App\Http\Requests\Management;

use App\Models\Office;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOfficeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Office::class);
    }

    /**
     * `office_name` is unique — the 95-row seeded dataset (offices.json)
     * has no duplicate names, so a hard uniqueness rule doesn't conflict
     * with re-seeding, and it's the field people scan the index/select-
     * dropdown by (Office::activeOptions()), so silently allowing two
     * identically-named rows would be a real source of misassignment.
     *
     * `school_id` is nullable, not required — only actual schools have a
     * DepEd School ID; division-level offices/units legitimately leave it
     * null (see Office's doc-comment).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'office_name' => ['required', 'string', 'max:255', Rule::unique('offices', 'office_name')],
            'office_type' => ['nullable', 'string', 'max:100'],
            'school_id' => ['nullable', 'integer'],
            'address' => ['nullable', 'string'],
            'region' => ['nullable', 'string', 'max:100'],
            'district' => ['nullable', 'string', 'max:100'],
            'division' => ['nullable', 'string', 'max:100'],
            'contact_number' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'school_id' => 'school ID',
            'is_active' => 'active status',
        ];
    }
}
