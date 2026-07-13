<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UserRoleUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('users.manage');
    }

    /**
     * `role_ids` — an array of role primary keys, not names: real RBAC
     * lets a user hold more than one role bundle at once (see
     * UserRoleService::syncRoles()), so the admin UI submits a
     * multi-select rather than a single dropdown value. `present` (not
     * `required`) so submitting an empty array — stripping a user down
     * to zero roles — is a valid, deliberate choice, not a validation
     * error.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'role_ids' => ['present', 'array'],
            'role_ids.*' => ['integer', Rule::exists('roles', 'id')],
        ];
    }

    /**
     * Self-escalation guard (2026-07 security review, forward-looking
     * requirement #1): a user must never be able to change their own
     * role set. Enforced here as a normal validation error — populating
     * the Inertia form's error bag on the `role_ids` field — rather than
     * a silent no-op, so the admin UI can surface exactly why the change
     * was rejected. UserRoleService re-checks the same invariant for any
     * caller that isn't this HTTP route.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $target = $this->route('user');

            if ($target instanceof User && $this->user()->is($target)) {
                $validator->errors()->add('role_ids', __('You cannot change your own roles.'));
            }
        });
    }
}
