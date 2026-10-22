<?php

namespace App\Http\Requests\Management;

use App\Enums\Permission;
use App\Http\Requests\Management\Concerns\ConfirmsCurrentPassword;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSecuritySettingRequest extends FormRequest
{
    use ConfirmsCurrentPassword;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can(Permission::SecuritySettingsManage->value);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'setting_key' => ['required', 'string'],
            'value' => ['required', 'integer'],
            'current_password' => ['required', 'string', 'current_password'],
        ];
    }
}
