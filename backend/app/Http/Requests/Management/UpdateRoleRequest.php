<?php

declare(strict_types=1);

namespace App\Http\Requests\Management;

use App\Enums\DataScope;
use App\Enums\Permission as PermissionEnum;
use App\Models\Role;
use App\Services\RoleService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Validator;

class UpdateRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('role'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $role = $this->route('role');

        return [
            // Lowercase-only by invariant — see StoreRoleRequest::rules()
            // for why 'alpha_dash' is supplemented with an explicit regex.
            'name' => [
                'required', 'string', 'max:255', 'alpha_dash', 'regex:/^[a-z0-9_-]+$/',
                Rule::unique('roles', 'name')->ignore($role instanceof Role ? $role->id : null),
            ],
            'label' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'data_scope' => ['sometimes', Rule::enum(DataScope::class)],
            'permissions' => ['array'],
            'permissions.*' => ['string', new Enum(PermissionEnum::class)],
        ];
    }

    /**
     * Grant ceiling and the Super Administrator rename block, surfaced as
     * field errors. RoleService::update() re-checks both for any other
     * caller.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $role = $this->route('role');

            if (! $role instanceof Role) {
                return;
            }

            // Grant ceiling: newly granted permissions must be ones the
            // actor holds (RoleService re-checks for every other caller).
            if (! $validator->errors()->has('permissions*')) {
                $unheld = app(RoleService::class)->unheldPermissionsGranted($this->user(), (array) $this->input('permissions', []), $role);

                if ($unheld !== []) {
                    $validator->errors()->add('permissions', RoleService::unheldPermissionsMessage($unheld));
                }
            }

            if (! $role->isProtected()) {
                return;
            }

            if ($this->input('name') !== $role->name) {
                $validator->errors()->add('name', __("The :label role's name cannot be changed.", ['label' => $role->label]));
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return (new StoreRoleRequest)->attributes();
    }
}
