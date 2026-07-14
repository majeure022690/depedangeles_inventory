<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UserStoreRequest extends FormRequest
{
    use PasswordValidationRules, ProfileValidationRules;

    public function authorize(): bool
    {
        return $this->user()->can('users.manage');
    }

    /**
     * The admin-provisioning counterpart to Fortify's self-registration
     * (CreateNewUser) — same name/email/password rules, reused via the
     * shared traits rather than duplicated. `role_ids` is optional at
     * creation time (an account with zero roles is valid — same
     * "deny-by-default" starting point self-registration gives every new
     * account via the 'pending' role); the permission-tier guard against
     * over-privileged role grants is enforced by
     * UserRoleService::createUser(), not duplicated here.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
            'office_id' => ['nullable', 'integer', Rule::exists('offices', 'id')],
            'role_ids' => ['present', 'array'],
            'role_ids.*' => ['integer', Rule::exists('roles', 'id')],
        ];
    }
}
