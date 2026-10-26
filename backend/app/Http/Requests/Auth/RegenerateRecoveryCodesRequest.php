<?php

namespace App\Http\Requests\Auth;

use App\Rules\CurrentPassword;
use Illuminate\Foundation\Http\FormRequest;

class RegenerateRecoveryCodesRequest extends FormRequest
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
            'current_password' => ['required', 'string', new CurrentPassword($this->user())],
        ];
    }
}
