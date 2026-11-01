<?php

declare(strict_types=1);

namespace App\Http\Requests\Management;

use App\Enums\DataScope;
use App\Models\Office;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Office, office type and district narrow the dashboard only for users who see every office;
 * anyone narrower is already limited to their own office.
 */
class ShowDashboardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'office_id' => ['nullable', 'integer'],
            'office_type' => ['nullable', 'string', 'max:255'],
            'district' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Office ids the dashboard is narrowed to, or null for no narrowing.
     *
     * @return list<int>|null
     */
    public function officeIds(): ?array
    {
        /** @var User $user */
        $user = $this->user();
        $filters = array_filter($this->safe()->only(['office_id', 'office_type', 'district']), fn ($value) => filled($value));

        if ($filters === [] || $user->dataScope() !== DataScope::All) {
            return null;
        }

        return Office::query()
            ->when(isset($filters['office_id']), fn ($query) => $query->whereKey((int) $filters['office_id']))
            ->when(isset($filters['office_type']), fn ($query) => $query->where('office_type', $filters['office_type']))
            ->when(isset($filters['district']), fn ($query) => $query->whereHas('stakeholderProfile', fn ($profile) => $profile->where('school_district', $filters['district'])))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
