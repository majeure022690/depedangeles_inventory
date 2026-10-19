<?php

declare(strict_types=1);

namespace App\Http\Requests\Management;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', User::class);
    }

    /**
     * Accounts are admin-created (there is no self-registration).
     * `role_ids` may be empty: an account with no roles reaches nothing.
     * Which roles the actor may hand out (permission-tier separation) is
     * enforced by UserRoleService::createUser(), not duplicated here.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class)],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
            'office_id' => ['nullable', 'integer', Rule::exists('offices', 'id')],
            'is_active' => ['sometimes', 'boolean'],
            'role_ids' => ['present', 'array'],
            'role_ids.*' => ['integer', 'distinct', Rule::exists('roles', 'id')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'office_id' => 'office',
            'is_active' => 'active status',
            'role_ids' => 'roles',
            'role_ids.*' => 'role',
        ];
    }
}
