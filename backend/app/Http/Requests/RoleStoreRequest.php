<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\Permission as PermissionEnum;
use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class RoleStoreRequest extends FormRequest
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
            // 'name' is the stable machine key (role_user/permission_role
            // FK target, and what CreateNewUser looks 'pending' up by) —
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
}
