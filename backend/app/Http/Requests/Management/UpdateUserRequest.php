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

        $rules = [
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

        if ($this->isSelfEdit()) {
            $rules['current_password'] = [
                Rule::requiredIf(fn () => $this->input('email') !== $this->user()->email),
                'nullable',
                'current_password',
            ];
        }

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($this->isSelfEdit() && $this->has('is_active') && ! $this->boolean('is_active')) {
                $validator->errors()->add('is_active', __("You can't deactivate your own account."));
            }
        });
    }

    private function isSelfEdit(): bool
    {
        $target = $this->route('user');

        return $target instanceof User && $this->user()->is($target);
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return (new StoreUserRequest)->attributes();
    }
}
