<?php

namespace App\Http\Requests\Auth;

use App\Rules\Recaptcha;
use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // DepEd addresses are printable ASCII; anything else is refused
            // before PRIME is queried (it could only raise a PRIME data error).
            'email' => ['required', 'string', 'email', 'max:255', 'regex:/^[\x20-\x7E]+$/'],
            'password' => ['required', 'string'],
            'recaptcha_token' => Recaptcha::rules('admin_login'),
        ];
    }
}
