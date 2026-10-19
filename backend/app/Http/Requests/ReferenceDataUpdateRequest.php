<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\ReferenceDataResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * ONE generic Form Request for all 13 lookup-normalization reference-data
 * tables (docs/architecture-decisions/lookup-normalization.md), not 13 or
 * even 2 — the only thing that varies between tiers is whether `name` is
 * accepted, so a single class branching on `tier` is simpler than
 * duplicating the shared description/sort_order/is_active rules across
 * separate Tier1/Tier2 classes. See ReferenceDataController's doc-comment
 * for the full Tier-1-vs-Tier-2 `name` editability reasoning this mirrors:
 *
 *  - Tier 1 (`item_types`, `brands`, ...): `name` IS editable. Consumers
 *    (Equipment, Personnel, IspAccount) hold a real `_id` foreign key and
 *    always read the display name through the `belongsTo` relationship,
 *    never a copied string — a rename propagates correctly everywhere by
 *    construction, so there's no stale-copy risk to guard against.
 *  - Tier 2 (`equipment_libraries`, ...): `type`/`value` are NEVER
 *    accepted, by omission — same pattern LookupUpdateRequest already
 *    established for the old `lookups` table. Tier 2 consumer columns
 *    stayed validated strings (ADR Question 2), so every historical
 *    record holds its own COPY of `value`; renaming it here would not
 *    retroactively update those rows, silently orphaning them.
 */
class ReferenceDataUpdateRequest extends FormRequest
{
    /**
     * Re-resolves the target row independently of the controller (see
     * ReferenceDataResolver's doc-comment for why) and defers to
     * ReferenceDataPolicy::update() via the model instance — exercising
     * exactly the dynamic-model Gate::policy() resolution the ADR asked
     * Backend to confirm works.
     */
    public function authorize(): bool
    {
        $row = ReferenceDataResolver::resolveRow(
            (string) $this->route('table'),
            (string) $this->route('id'),
        );

        return $this->user()?->can('update', $row) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $entry = ReferenceDataResolver::entry((string) $this->route('table'));

        $rules = [
            'description' => ['nullable', 'string', 'max:65535'],
            'sort_order' => ['required', 'integer', 'min:0'],
            'is_active' => ['required', 'boolean'],
        ];

        if ($entry['tier'] === 1) {
            $table = (new $entry['model'])->getTable();

            $rules['name'] = [
                'required',
                'string',
                'max:255',
                Rule::unique($table, 'name')->ignore($this->route('id')),
            ];
        }

        return $rules;
    }
}
