<?php

declare(strict_types=1);

namespace App\Http\Requests\Management;

use App\Http\Requests\Management\Concerns\RestrictsScopedAssignments;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

class StoreUserRequest extends FormRequest
{
    use RestrictsScopedAssignments;

    public function authorize(): bool
    {
        return $this->user()->can('create', User::class);
    }

    /**
     * Accounts are admin-created (there is no self-registration).
     * `role_id` is required: every user must have exactly one role.
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
            'office_id' => ['nullable', 'integer', $this->activeOfficeRule()],
            'is_active' => ['sometimes', 'boolean'],
            'role_id' => ['required', 'integer', Rule::exists('roles', 'id')],
        ];
    }

    /**
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            $this->lockForScopedActors(['office_id' => [$this->user()->office_id, null]]),
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
            'role_id' => 'role',
        ];
    }
}
