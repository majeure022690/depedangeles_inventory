<?php

namespace App\Http\Requests\Management;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class ResetTwoFactorRequest extends FormRequest
{
    public function authorize(): bool
    {
        $target = $this->route('user');

        return $target instanceof User && $this->user()->can('resetTwoFactor', $target);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'current_password'],
        ];
    }
}
