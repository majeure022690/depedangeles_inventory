<?php

declare(strict_types=1);

namespace App\Http\Requests\Management;

use App\Http\Requests\Management\Concerns\RestrictsScopedAssignments;
use App\Http\Requests\Management\Concerns\ValidatesPropertyItemFields;
use App\Models\PropertyItem;
use Closure;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

class UpdatePropertyItemRequest extends FormRequest
{
    use RestrictsScopedAssignments, ValidatesPropertyItemFields;

    public function authorize(): Response
    {
        return Gate::forUser($this->user())->inspect('update', $this->propertyItem());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...$this->propertyItemRules(),
            'office_id' => ['required', 'integer', $this->activeOfficeRule($this->propertyItem()->office_id)],
        ];
    }

    /**
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            $this->lockForScopedActors(['office_id' => [$this->propertyItem()->office_id]]),
            $this->requirePersonnelAtOffice($this->filled('office_id') ? $this->integer('office_id') : null),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return $this->propertyItemAttributes();
    }

    private function propertyItem(): PropertyItem
    {
        /** @var PropertyItem */
        return $this->route('propertyItem');
    }
}
