<?php

namespace App\Http\Requests\Management\Concerns;

use App\Enums\DataScope;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Validator;

trait RestrictsScopedAssignments
{
    protected function actorHasAllScope(): bool
    {
        return $this->user()->dataScope() === DataScope::All;
    }

    /**
     * Active offices, plus the record's current office so an unchanged
     * value still validates after that office is deactivated.
     */
    protected function activeOfficeRule(?int $current = null): Exists
    {
        return Rule::exists('offices', 'id')->where(fn (Builder $query) => $query
            ->where('is_active', true)
            ->when($current !== null, fn (Builder $query) => $query->orWhere('id', $current)));
    }

    /**
     * Rejects scoped actors' values for the given id fields outside the allowed ones.
     *
     * @param  array<string, list<int|null>>  $allowed
     * @return Closure(Validator): void
     */
    protected function lockForScopedActors(array $allowed, bool $allowBlank = false): Closure
    {
        return function (Validator $validator) use ($allowed, $allowBlank): void {
            if ($this->actorHasAllScope()) {
                return;
            }

            foreach ($allowed as $field => $values) {
                if (! $this->exists($field) || $validator->errors()->has($field)) {
                    continue;
                }

                $input = $this->input($field);
                $input = blank($input) ? null : (int) $input;

                if ($input === null && $allowBlank) {
                    continue;
                }

                $values = array_map(fn (mixed $value): ?int => $value === null ? null : (int) $value, $values);

                if (! in_array($input, $values, true)) {
                    $validator->errors()->add($field, __('Only users with access to all records can change this.'));
                }
            }
        };
    }

    /**
     * Rejects scoped actors' changes to the given string fields, compared trimmed and as strings.
     *
     * @param  array<string, string|null>  $current
     * @return Closure(Validator): void
     */
    protected function lockUnchangedForScopedActors(array $current): Closure
    {
        return function (Validator $validator) use ($current): void {
            if ($this->actorHasAllScope()) {
                return;
            }

            foreach ($current as $field => $value) {
                if (! $this->exists($field) || $validator->errors()->has($field)) {
                    continue;
                }

                $input = $this->input($field);

                if (trim(is_scalar($input) ? (string) $input : '') !== trim((string) $value)) {
                    $validator->errors()->add($field, __('Only users with access to all records can change this.'));
                }
            }
        };
    }

    /**
     * Scoped actors' new records are forced into their office, so they need one.
     *
     * @return Closure(Validator): void
     */
    protected function requireActorOffice(): Closure
    {
        return function (Validator $validator): void {
            if (! $this->actorHasAllScope() && $this->user()->office_id === null) {
                $validator->errors()->add('office_id', __('Your account is not assigned to an office.'));
            }
        };
    }
}
