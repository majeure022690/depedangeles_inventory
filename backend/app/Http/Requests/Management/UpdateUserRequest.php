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
     * `role_id` replaces the user's role (a user holds exactly one role).
     * No password field: PRIME owns passwords; local-only accounts use `users:set-password`.
     * Name, email, office and employee number are managed by PRIME.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'is_active' => ['sometimes', 'boolean'],
            'role_id' => ['required', 'integer', Rule::exists('roles', 'id')],
        ];
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
