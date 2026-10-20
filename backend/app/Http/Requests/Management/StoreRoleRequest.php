<?php

declare(strict_types=1);

namespace App\Http\Requests\Management;

use App\Enums\Permission as PermissionEnum;
use App\Models\Role;
use App\Services\RoleService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Validator;

class StoreRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Role::class);
    }

    /**
     * `permissions` is validated element-by-element against
     * App\Enums\Permission — the fixed, code-defined catalog stays the
     * only source of truth for what a valid permission string is, per
     * this app's RBAC design: roles are dynamic, the permission catalog
     * is not.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // 'name' is the stable machine key (Spatie looks roles up by
            // name, and Role::PENDING is matched by it) —
            // kept to a URL/identifier-safe charset, distinct from the
            // free-text 'label' shown in the UI. Lowercase-only by
            // invariant (matches the seeded pending/viewer/encoder/
            // admin names) — 'alpha_dash' alone permits
            // uppercase, so it's supplemented with an explicit regex
            // rather than relying on it to enforce case.
            'name' => ['required', 'string', 'max:255', 'alpha_dash', 'regex:/^[a-z0-9_-]+$/', Rule::unique('roles', 'name')],
            'label' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'permissions' => ['array'],
            'permissions.*' => ['string', new Enum(PermissionEnum::class)],
        ];
    }

    /**
     * Grant ceiling: only permissions the actor holds (RoleService
     * re-checks for every other caller).
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->has('permissions*')) {
                return;
            }

            $unheld = app(RoleService::class)->unheldPermissionsGranted($this->user(), (array) $this->input('permissions', []));

            if ($unheld !== []) {
                $validator->errors()->add('permissions', RoleService::unheldPermissionsMessage($unheld));
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'permissions.*' => 'permission',
        ];
    }
}
