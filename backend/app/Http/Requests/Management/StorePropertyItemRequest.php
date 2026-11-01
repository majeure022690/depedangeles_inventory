<?php

declare(strict_types=1);

namespace App\Http\Requests\Management;

use App\Http\Requests\Management\Concerns\RestrictsScopedAssignments;
use App\Http\Requests\Management\Concerns\ValidatesPropertyItemFields;
use App\Models\PropertyItem;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StorePropertyItemRequest extends FormRequest
{
    use RestrictsScopedAssignments, ValidatesPropertyItemFields;

    public function authorize(): bool
    {
        return $this->user()->can('create', PropertyItem::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...$this->propertyItemRules(),
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
            $this->requirePersonnelAtOffice($this->targetOfficeId()),
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
        return $this->propertyItemAttributes();
    }
}
