<?php

declare(strict_types=1);

namespace App\Http\Requests\Management;

use App\Enums\ConnectivityLibraryType;
use App\Http\Requests\Management\Concerns\NormalizesDateTimesToAppTimezone;
use App\Models\ConnectivityLibrary;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StoreIspSpeedTestRequest extends FormRequest
{
    use NormalizesDateTimesToAppTimezone;

    protected function prepareForValidation(): void
    {
        $this->normalizeDateTimesToAppTimezone('tested_at');
    }

    /**
     * Logging a speed test authorizes against `update` on the parent
     * IspAccount — there's no separate permission for it (see
     * Permission::IspAccountsEdit's doc-comment and IspAccountPolicy),
     * the same way equipment transactions authorize against the parent
     * Equipment.
     */
    public function authorize(): Response
    {
        return Gate::forUser($this->user())->inspect('update', $this->route('ispAccount'));
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

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'rate_isp_service' => 'ISP service rating',
        ];
    }
}
