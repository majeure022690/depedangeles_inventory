<?php

declare(strict_types=1);

namespace App\Http\Requests\Management;

use App\Models\EquipmentAttachment;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StoreEquipmentAttachmentRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::forUser($this->user())->inspect('update', $this->route('equipment'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:'.EquipmentAttachment::MAX_KILOBYTES, 'mimes:pdf,jpg,jpeg,png,webp'],
            'document_type' => ['required', 'string', Rule::in(EquipmentAttachment::documentTypes())],
            'document_no' => ['nullable', 'string', 'max:255'],
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

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'document_type' => 'document type',
            'document_no' => 'document number',
        ];
    }
}
