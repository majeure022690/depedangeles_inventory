<?php

declare(strict_types=1);

namespace App\Http\Requests\Management;

use App\Http\Requests\Management\Concerns\RestrictsScopedAssignments;
use App\Http\Requests\Management\Concerns\ValidatesIspAccountFields;
use App\Models\IspAccount;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreIspAccountRequest extends FormRequest
{
    use RestrictsScopedAssignments, ValidatesIspAccountFields;

    public function authorize(): bool
    {
        return $this->user()->can('create', IspAccount::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...$this->ispAccountRules(),
            'office_id' => ['nullable', 'integer', $this->activeOfficeRule()],
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
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return $this->ispAccountAttributes();
    }
}
