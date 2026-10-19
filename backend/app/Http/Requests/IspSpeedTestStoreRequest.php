<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\ConnectivityLibraryType;
use App\Models\ConnectivityLibrary;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IspSpeedTestStoreRequest extends FormRequest
{
    /**
     * Logging a speed test authorizes against `update` on the parent
     * IspAccount — there's no separate permission for it (see
     * Permission::IspAccountsEdit's doc-comment and IspAccountPolicy),
     * the same way equipment transactions authorize against the parent
     * Equipment.
     */
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('ispAccount'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'download_speed' => ['required', 'numeric', 'min:0'],
            'upload_speed' => ['required', 'numeric', 'min:0'],
            'ping' => ['nullable', 'integer', 'min:0'],
            'tested_at' => ['required', 'date'],
            'signal_quality' => ['required', 'string', Rule::in(ConnectivityLibrary::activeValues(ConnectivityLibraryType::SignalQuality))],
            'rate_isp_service' => ['nullable', 'integer', 'min:1', 'max:5'],
        ];
    }
}
