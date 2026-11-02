<?php

declare(strict_types=1);

namespace App\Http\Requests\Management;

use App\Enums\Permission;
use App\Models\EquipmentAttachment;
use Illuminate\Foundation\Http\FormRequest;

class StoreTransactionDocumentUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasAnyPermission([Permission::EquipmentTransactionsCreate, Permission::EquipmentCreate]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:'.EquipmentAttachment::MAX_KILOBYTES, 'mimes:pdf,jpg,jpeg,png,webp'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.max' => __('The file must be 5 MB or smaller. Scan at a lower quality or in black-and-white and try again.'),
            'file.mimes' => __('Only PDF, JPG, PNG and WEBP files are accepted.'),
        ];
    }
}
