<script setup lang="ts" generic="TForm extends Record<string, unknown>, TValue extends string | number = string">
import type { InertiaForm } from '@inertiajs/vue3';
import type { CheckboxCheckedState } from 'reka-ui';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { useCheckboxGroupField } from '@/composables/useCheckboxGroupField';

/**
 * Renders a lookup-backed multi-select as a grid of checkboxes bound to a
 * single JSON array field on an Inertia form (add on check, remove on
 * uncheck) — see useCheckboxGroupField for the underlying toggle logic.
 * Shared by the Stakeholder Profile and Internet Connectivity Survey
 * forms (string-valued `LookupOption[]`, the default `TValue = string`)
 * and by Personnel's `fund_source` (id-valued `ReferenceOption[]`,
 * `TValue = number` — ADR Question 4).
 */
const props = withDefaults(
    defineProps<{
        form: InertiaForm<TForm>;
        field: keyof TForm;
        options: { value: TValue; label: string }[];
        idPrefix: string;
        disabled?: boolean;
    }>(),
    {
        disabled: false,
    },
);

const { isChecked, toggle } = useCheckboxGroupField<TForm, TValue>(props.form, props.field);

function onUpdate(value: TValue, checked: CheckboxCheckedState): void {
    toggle(value, checked === true);
}
</script>

<template>
    <div class="grid gap-x-6 gap-y-3 sm:grid-cols-2">
        <div v-for="opt in options" :key="opt.value" class="flex items-center gap-3">
            <Checkbox
                :id="`${idPrefix}-${opt.value}`"
                :model-value="isChecked(opt.value)"
                :disabled="disabled"
                @update:model-value="(checked) => onUpdate(opt.value, checked)"
            />
            <Label :for="`${idPrefix}-${opt.value}`" class="font-normal">{{ opt.label }}</Label>
        </div>
    </div>
</template>
