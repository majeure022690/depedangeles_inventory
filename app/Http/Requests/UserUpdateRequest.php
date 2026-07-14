<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Concerns\ProfileValidationRules;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UserUpdateRequest extends FormRequest
{
    use ProfileValidationRules;

    public function authorize(): bool
    {
        return $this->user()->can('users.manage');
    }

    /**
     * `name`/`email` reuse the same rules Fortify's own profile update
     * uses (ProfileValidationRules), scoped to exclude the target user
     * from the email-uniqueness check. `role_ids` — an array of role
     * primary keys, not names: real RBAC lets a user hold more than one
     * role bundle at once (see UserRoleService::syncRoles()), so the
     * admin UI submits a multi-select rather than a single dropdown
     * value. `present` (not `required`) so submitting an empty array —
     * stripping a user down to zero roles — is a valid, deliberate
     * choice, not a validation error.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $target = $this->route('user');

        return [
            ...$this->profileRules($target instanceof User ? $target->id : null),
            'office_id' => ['nullable', 'integer', Rule::exists('offices', 'id')],
            'role_ids' => ['present', 'array'],
            'role_ids.*' => ['integer', Rule::exists('roles', 'id')],
        ];
    }

    /**
     * Self-escalation guard (2026-07 security review, forward-looking
     * requirement #1): a user must never be able to edit their own
     * account — roles or profile — through this admin screen. Enforced
     * here as a normal validation error — populating the Inertia form's
     * error bag on the `role_ids` field — rather than a silent no-op, so
     * the admin UI can surface exactly why the change was rejected.
     * UserRoleService::updateUser() re-checks the same invariant for any
     * caller that isn't this HTTP route.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $target = $this->route('user');

            if ($target instanceof User && $this->user()->is($target)) {
                $validator->errors()->add('role_ids', __('You cannot edit your own account here — use Settings instead.'));
            }
        });
    }
}
