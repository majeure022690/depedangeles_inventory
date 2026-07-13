<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\Permission as PermissionEnum;
use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Validator;

class RoleUpdateRequest extends FormRequest
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
            // Lowercase-only by invariant — see RoleStoreRequest::rules()
            // for why 'alpha_dash' is supplemented with an explicit regex.
            'name' => [
                'required', 'string', 'max:255', 'alpha_dash', 'regex:/^[a-z0-9_-]+$/',
                Rule::unique('roles', 'name')->ignore($role instanceof Role ? $role->id : null),
            ],
            'label' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'permissions' => ['array'],
            'permissions.*' => ['string', new Enum(PermissionEnum::class)],
        ];
    }

    /**
     * Protected-'pending'-role guard (see Role::PENDING's doc-comment):
     * populates the Inertia form's error bag the same way
     * UserRoleUpdateRequest's self-escalation guard does, rather than a
     * silent no-op. RoleService::update() re-checks both invariants for
     * any caller that isn't this HTTP route.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $role = $this->route('role');

            if (! $role instanceof Role || ! $role->isProtected()) {
                return;
            }

            if ($this->input('name') !== $role->name) {
                $validator->errors()->add('name', __("The :label role's name cannot be changed.", ['label' => $role->label]));
            }

            if (! empty($this->input('permissions', []))) {
                $validator->errors()->add('permissions', __(
                    'The :label role must always have zero permissions — it is the deny-by-default '.
                    'holding pen for new self-registrations.',
                    ['label' => $role->label],
                ));
            }
        });
    }
}
