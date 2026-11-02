<?php

declare(strict_types=1);

namespace App\Http\Requests\Management;

use App\Enums\Permission;
use App\Models\Equipment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;

class ListSchoolPropertyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermissionTo(Permission::PropertyItemsView);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'office_id' => ['nullable', 'string', 'regex:/^(\d+|none)$/'],
            'item_type_id' => ['nullable', 'integer'],
            'brand_id' => ['nullable', 'integer'],
            'functional' => ['nullable', 'string', 'in:functional,non_functional'],
            'equipment_condition_id' => ['nullable', 'integer'],
            'disposition_status' => ['nullable', 'string', 'max:100'],
            'estimated_useful_life' => ['nullable', 'integer', 'min:0', 'max:100'],
            'transaction_type' => ['nullable', 'string', 'max:100'],
            'accountable_officer_id' => ['nullable', 'integer'],
            'received_by_id' => ['nullable', 'integer'],
        ];
    }

    /**
     * `office_id=none` keeps the items no office claims; a numeric id keeps that office's items.
     *
     * @param  Builder<Equipment>  $query
     */
    public function narrowToOffice(Builder $query): void
    {
        $office = $this->safe()->string('office_id')->toString();

        if ($office === 'none') {
            $query->whereRaw('('.Equipment::OFFICE_SQL.') IS NULL');
        } elseif ($office !== '') {
            $query->atOffice((int) $office);
        }
    }

    /**
     * Equipment still in custody that the user can see, narrowed by every filter except the office.
     *
     * @return Builder<Equipment>
     */
    public function equipment(): Builder
    {
        /** @var User $user */
        $user = $this->user();
        $filters = $this->safe();

        return Equipment::query()
            ->visibleTo($user)
            ->inCustody()
            ->search($filters->string('search')->toString() ?: null)
            ->when($filters->filled('item_type_id'), fn (Builder $query) => $query->where('item_type_id', $filters->integer('item_type_id')))
            ->when($filters->filled('brand_id'), fn (Builder $query) => $query->where('brand_id', $filters->integer('brand_id')))
            ->when($filters->filled('functional'), fn (Builder $query) => $query->where('non_functional', $filters->string('functional')->toString() === 'non_functional'))
            ->condition($filters->filled('equipment_condition_id') ? $filters->integer('equipment_condition_id') : null)
            ->dispositionStatus($filters->string('disposition_status')->toString() ?: null)
            ->when($filters->filled('estimated_useful_life'), fn (Builder $query) => $query->where('estimated_useful_life', $filters->integer('estimated_useful_life')))
            ->when($filters->filled('accountable_officer_id'), fn (Builder $query) => $query->where('current_accountable_officer_id', $filters->integer('accountable_officer_id')))
            ->latestTransaction($filters->string('transaction_type')->toString() ?: null, $filters->filled('received_by_id') ? $filters->integer('received_by_id') : null);
    }
}
