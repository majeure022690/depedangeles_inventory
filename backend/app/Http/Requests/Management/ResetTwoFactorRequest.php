<?php

namespace App\Http\Requests\Management;

use App\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;

class ResetTwoFactorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permission::UsersManage->value);
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
