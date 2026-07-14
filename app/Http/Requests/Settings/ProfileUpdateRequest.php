<?php

namespace App\Http\Requests\Settings;

use App\Concerns\ProfileValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ProfileUpdateRequest extends FormRequest
{
    use ProfileValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * `avatar` is deliberately not part of the shared ProfileValidationRules
     * trait (unlike name/email) — UserStoreRequest/UserUpdateRequest (the
     * admin-provisioning /users screen) reuse that trait too, and an admin
     * provisioning someone else's account has no business uploading a
     * photo on their behalf.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...$this->profileRules($this->user()->id),
            'avatar' => ['nullable', 'image', 'mimes:jpeg,png,webp', 'max:2048'],
        ];
    }
}
