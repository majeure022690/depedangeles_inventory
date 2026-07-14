<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\ReferenceDataResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * ONE generic Form Request for creating a new row in any of the 13
 * lookup-normalization reference-data tables (docs/architecture-decisions/
 * lookup-normalization.md) — mirrors ReferenceDataUpdateRequest's shape
 * (branch on tier, not a class per table/tier), for the same reason: the
 * only thing that varies is which identifying field(s) the tier accepts.
 *
 *  - Tier 1 (`item_types`, `brands`, ...): accepts `name`, unique within
 *    the table — same editability reasoning as
 *    ReferenceDataUpdateRequest's `name` rule, just enforced fresh on
 *    create instead of ignoring the current row.
 *  - Tier 2 (`equipment_libraries`, ...): accepts `type` (must be one of
 *    the table's own backed enum cases, e.g. EquipmentLibraryType) and
 *    `value`, unique within that `type` — two rows in the same physical
 *    table but different `type`s are allowed to share a `value` (they're
 *    different vocabularies), matching how ReferenceDataResolver's
 *    Tier 2 usage-guard on delete also scopes by type+value together, not
 *    value alone.
 */
class ReferenceDataStoreRequest extends FormRequest
{
    /**
     * Mirrors ReferenceDataUpdateRequest::authorize()'s shape: resolves
     * the target model class independently of the controller and defers
     * to ReferenceDataPolicy::create() via the model's FQCN — exercising
     * the same dynamic-model Gate::policy() resolution, just against a
     * class string instead of an instance (there's no row yet to check
     * against on create).
     */
    public function authorize(): bool
    {
        $entry = ReferenceDataResolver::entry((string) $this->route('table'));

        return $this->user()?->can('create', $entry['model']) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $entry = ReferenceDataResolver::entry((string) $this->route('table'));
        $table = (new $entry['model'])->getTable();

        $rules = [
            'description' => ['nullable', 'string', 'max:65535'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ];

        if ($entry['tier'] === 1) {
            $rules['name'] = ['required', 'string', 'max:255', Rule::unique($table, 'name')];

            return $rules;
        }

        $rules['type'] = ['required', new Enum($entry['type_enum'])];
        $rules['value'] = [
            'required',
            'string',
            'max:255',
            Rule::unique($table, 'value')->where('type', $this->input('type')),
        ];

        return $rules;
    }
}
