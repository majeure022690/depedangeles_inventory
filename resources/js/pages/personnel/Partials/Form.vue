<script setup lang="ts">
import type { InertiaForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import CheckboxGroupField from '@/components/CheckboxGroupField.vue';
import InputError from '@/components/InputError.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { PersonnelFormData, PersonnelFormOptions } from '@/types/personnel';

const props = defineProps<{
    form: InertiaForm<PersonnelFormData>;
    options: PersonnelFormOptions;
}>();

/** Sentinel used only inside the UI to represent "no selection" for an
 * optional lookup-backed dropdown — Reka's Select forbids an empty-string
 * item value, so a clearable field needs a distinct placeholder value that
 * gets translated back to '' (Tier 2 string fields) or null (Tier 1 id
 * fields) before it ever reaches the form payload. */
const NONE = '__none__';

function nullableSelect(field: 'suffix' | 'separation_cause') {
    return computed<string>({
        get: () => props.form[field] || NONE,
        set: (value: string) => {
            // eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object.
            props.form[field] = value === NONE ? '' : value;
        },
    });
}

/**
 * Tier 1 (lookup-normalization ADR) equivalent of nullableSelect() above,
 * for the 3 nullable FK id fields (`position_id`, `ro_office_id`,
 * `sdo_office_id`) — same NONE-sentinel pattern, translated to/from `null`
 * instead of `''`.
 */
function nullableIdSelect(field: 'position_id' | 'ro_office_id' | 'sdo_office_id') {
    return computed<number | typeof NONE>({
        get: () => props.form[field] ?? NONE,
        set: (value: number | typeof NONE) => {
            // eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object.
            props.form[field] = value === NONE ? null : Number(value);
        },
    });
}

const suffixModel = nullableSelect('suffix');
const separationCauseModel = nullableSelect('separation_cause');
const positionModel = nullableIdSelect('position_id');
const roOfficeModel = nullableIdSelect('ro_office_id');
const sdoOfficeModel = nullableIdSelect('sdo_office_id');
</script>

<template>
    <div class="grid gap-6 lg:grid-cols-2 items-start">
        <fieldset class="space-y-4 rounded-lg border border-input bg-muted/30 p-4">
            <legend class="px-1 text-base font-medium">Identification</legend>
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="employee_id">Employee ID</Label>
                    <!-- eslint-disable vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Input
                        id="employee_id"
                        v-model="form.employee_id"
                        required
                        autocomplete="off"
                    />
                    <!-- eslint-enable vue/no-mutating-props -->
                    <InputError :message="form.errors.employee_id" />
                </div>
                <div class="grid gap-2">
                    <Label for="suffix">Suffix</Label>
                    <Select v-model="suffixModel">
                        <SelectTrigger id="suffix" class="w-full">
                            <SelectValue placeholder="None" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem :value="NONE">None</SelectItem>
                            <SelectItem
                                v-for="opt in options.name_suffix"
                                :key="opt.value"
                                :value="opt.value"
                            >
                                {{ opt.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="form.errors.suffix" />
                </div>
                <div class="grid gap-2">
                    <Label for="last_name">Last name</Label>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Input id="last_name" v-model="form.last_name" required />
                    <InputError :message="form.errors.last_name" />
                </div>
                <div class="grid gap-2">
                    <Label for="first_name">First name</Label>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Input id="first_name" v-model="form.first_name" required />
                    <InputError :message="form.errors.first_name" />
                </div>
                <div class="grid gap-2">
                    <Label for="middle_name">Middle name</Label>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Input id="middle_name" v-model="form.middle_name" />
                    <InputError :message="form.errors.middle_name" />
                </div>
            </div>
        </fieldset>

        <fieldset class="space-y-4 rounded-lg border border-input bg-muted/30 p-4">
            <legend class="px-1 text-base font-medium">Assignment</legend>
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="position_id">Position</Label>
                    <Select v-model="positionModel">
                        <SelectTrigger id="position_id" class="w-full">
                            <SelectValue placeholder="None" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem :value="NONE">None</SelectItem>
                            <SelectItem
                                v-for="opt in options.position"
                                :key="opt.value"
                                :value="opt.value"
                            >
                                {{ opt.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="form.errors.position_id" />
                </div>
                <div class="grid gap-2">
                    <Label for="ro_office_id">RO division</Label>
                    <Select v-model="roOfficeModel">
                        <SelectTrigger id="ro_office_id" class="w-full">
                            <SelectValue placeholder="None" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem :value="NONE">None</SelectItem>
                            <SelectItem
                                v-for="opt in options.ro_office"
                                :key="opt.value"
                                :value="opt.value"
                            >
                                {{ opt.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="form.errors.ro_office_id" />
                </div>
                <div class="grid gap-2">
                    <Label for="sdo_office_id">Division/unit</Label>
                    <Select v-model="sdoOfficeModel">
                        <SelectTrigger id="sdo_office_id" class="w-full">
                            <SelectValue placeholder="None" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem :value="NONE">None</SelectItem>
                            <SelectItem
                                v-for="opt in options.sdo_office"
                                :key="opt.value"
                                :value="opt.value"
                            >
                                {{ opt.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="form.errors.sdo_office_id" />
                </div>
                <div class="flex items-end gap-2 pb-2">
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Checkbox id="oic" v-model="form.oic" />
                    <Label for="oic">Officer-in-charge (OIC)</Label>
                </div>
                <div v-if="form.oic" class="grid gap-2 sm:col-span-2">
                    <Label for="oic_office">OIC office</Label>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Input id="oic_office" v-model="form.oic_office" />
                    <InputError :message="form.errors.oic_office" />
                </div>
            </div>
        </fieldset>

        <fieldset class="space-y-4 rounded-lg border border-input bg-muted/30 p-4">
            <legend class="px-1 text-base font-medium">Contact information</legend>
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="mobile_1">Mobile number 1</Label>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Input id="mobile_1" v-model="form.mobile_1" autocomplete="tel" />
                    <InputError :message="form.errors.mobile_1" />
                </div>
                <div class="grid gap-2">
                    <Label for="mobile_2">Mobile number 2</Label>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Input id="mobile_2" v-model="form.mobile_2" autocomplete="tel" />
                    <InputError :message="form.errors.mobile_2" />
                </div>
                <div class="grid gap-2">
                    <Label for="deped_email">DepEd email</Label>
                    <!-- eslint-disable vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Input
                        id="deped_email"
                        v-model="form.deped_email"
                        type="email"
                        autocomplete="email"
                    />
                    <!-- eslint-enable vue/no-mutating-props -->
                    <InputError :message="form.errors.deped_email" />
                </div>
                <div class="grid gap-2">
                    <Label for="personal_email">Personal email</Label>
                    <!-- eslint-disable vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Input
                        id="personal_email"
                        v-model="form.personal_email"
                        type="email"
                        autocomplete="email"
                    />
                    <!-- eslint-enable vue/no-mutating-props -->
                    <InputError :message="form.errors.personal_email" />
                </div>
            </div>
        </fieldset>

        <fieldset class="space-y-4 rounded-lg border border-input bg-muted/30 p-4">
            <legend class="px-1 text-base font-medium">Employment</legend>
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="date_hired">Date hired</Label>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Input id="date_hired" v-model="form.date_hired" type="date" />
                    <InputError :message="form.errors.date_hired" />
                </div>
                <div class="flex items-end gap-2 pb-2">
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Checkbox id="non_deped_funded" v-model="form.non_deped_funded" />
                    <Label for="non_deped_funded">Non-DepEd funded</Label>
                </div>
            </div>
            <fieldset v-if="form.non_deped_funded" class="grid gap-4">
                <legend class="mb-2 text-sm font-medium">Funding source</legend>
                <CheckboxGroupField
                    :form="form"
                    field="fund_source"
                    :options="options.teachers_funding_source"
                    id-prefix="fund_source"
                />
                <InputError :message="form.errors.fund_source" />
            </fieldset>
        </fieldset>

        <fieldset class="space-y-4 rounded-lg border border-input bg-muted/30 p-4">
            <legend class="px-1 text-base font-medium">Status</legend>
            <div class="flex items-center gap-3">
                <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                <Checkbox id="inactive" v-model="form.inactive" />
                <Label for="inactive">Inactive / separated</Label>
            </div>
            <div v-if="form.inactive" class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="separation_date">Separation date</Label>
                    <!-- eslint-disable vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Input
                        id="separation_date"
                        v-model="form.separation_date"
                        type="date"
                    />
                    <!-- eslint-enable vue/no-mutating-props -->
                    <InputError :message="form.errors.separation_date" />
                </div>
                <div class="grid gap-2">
                    <Label for="separation_cause">Cause of separation</Label>
                    <Select v-model="separationCauseModel">
                        <SelectTrigger id="separation_cause" class="w-full">
                            <SelectValue placeholder="None" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem :value="NONE">None</SelectItem>
                            <SelectItem
                                v-for="opt in options.cause_of_separation"
                                :key="opt.value"
                                :value="opt.value"
                            >
                                {{ opt.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="form.errors.separation_cause" />
                </div>
                <div class="grid gap-2">
                    <Label for="transferred_from">Transferred from</Label>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Input id="transferred_from" v-model="form.transferred_from" />
                    <InputError :message="form.errors.transferred_from" />
                </div>
                <div class="grid gap-2">
                    <Label for="transferred_to">Transferred to</Label>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Input id="transferred_to" v-model="form.transferred_to" />
                    <InputError :message="form.errors.transferred_to" />
                </div>
            </div>
        </fieldset>
    </div>
</template>
