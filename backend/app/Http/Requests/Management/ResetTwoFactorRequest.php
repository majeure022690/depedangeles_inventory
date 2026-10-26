<?php

namespace App\Http\Requests\Management;

use App\Http\Requests\Management\Concerns\ConfirmsCurrentPassword;
use App\Models\User;
use App\Rules\CurrentPassword;
use Illuminate\Foundation\Http\FormRequest;

class ResetTwoFactorRequest extends FormRequest
{
    use ConfirmsCurrentPassword;

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
            'current_password' => ['required', 'string', new CurrentPassword($this->user())],
        ];
    }
}
