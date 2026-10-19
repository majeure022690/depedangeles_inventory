<?php

declare(strict_types=1);

namespace App\Http\Requests\Management;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $target = $this->route('user');

        return $target instanceof User && $this->user()->can('update', $target);
    }

    /**
     * `role_ids` replaces the user's whole role set (a user can hold
     * several roles); `present` rather than `required` so an empty array,
     * stripping every role, is a deliberate valid choice. No password
     * field: a user who lost theirs uses the forgot-password flow.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $target = $this->route('user');

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'string', 'email', 'max:255',
                Rule::unique(User::class)->ignore($target instanceof User ? $target->id : null),
            ],
            'office_id' => ['nullable', 'integer', Rule::exists('offices', 'id')],
            'is_active' => ['sometimes', 'boolean'],
            'role_ids' => ['present', 'array'],
            'role_ids.*' => ['integer', 'distinct', Rule::exists('roles', 'id')],
        ];
    }

    /**
     * Self-escalation guard: nobody edits their own account here (roles,
     * profile or is_active, so no self-deactivation either). Surfaced as
     * a field error so the admin UI can say why; UserRoleService
     * re-checks it for every other caller.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $target = $this->route('user');

            if ($target instanceof User && $this->user()->is($target)) {
                $validator->errors()->add('role_ids', __('You cannot edit your own account here — use your profile instead.'));
            }
        });
    }
}
