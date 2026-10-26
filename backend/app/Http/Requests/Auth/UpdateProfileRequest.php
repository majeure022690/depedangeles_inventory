<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Avatar only. Name, email and password come from PRIME and are changed
 * there; a developer break-glass account uses `users:set-password`.
 */
class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Rejected rather than silently ignored, so a stale client can't
            // look like it changed the password.
            'password' => ['prohibited'],
            'avatar' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'remove_avatar' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'password.prohibited' => __('Passwords are managed in PRIME.'),
        ];
    }
}
