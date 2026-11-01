<?php

declare(strict_types=1);

namespace App\Http\Requests\Management;

use App\Http\Requests\Management\Concerns\RestrictsScopedAssignments;
use App\Http\Requests\Management\Concerns\ValidatesGspIctProfileFields;
use App\Models\GspIctProfile;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreGspIctProfileRequest extends FormRequest
{
    use RestrictsScopedAssignments, ValidatesGspIctProfileFields;

    public function authorize(): bool
    {
        return $this->user()->can('create', GspIctProfile::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...$this->gspIctProfileRules($this->targetOfficeId()),
            'office_id' => [$this->actorHasAllScope() ? 'required' : 'nullable', 'integer', $this->activeOfficeRule()],
        ];
    }

    /**
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            $this->requireActorOffice(),
            $this->lockForScopedActors(['office_id' => [$this->user()->office_id]], allowBlank: true),
            $this->requireOfficeIspAccounts($this->targetOfficeId()),
        ];
    }

    public function targetOfficeId(): ?int
    {
        return $this->actorHasAllScope()
            ? ($this->filled('office_id') ? $this->integer('office_id') : null)
            : $this->user()->office_id;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return $this->gspIctProfileAttributes();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->gspIctProfileMessages();
    }
}
