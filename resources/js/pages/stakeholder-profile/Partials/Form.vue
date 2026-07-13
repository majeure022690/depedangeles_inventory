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
import { Textarea } from '@/components/ui/textarea';
import type { StakeholderProfileFormData, StakeholderProfileFormOptions } from '@/types/stakeholder-profile';

const props = withDefaults(
    defineProps<{
        form: InertiaForm<StakeholderProfileFormData>;
        options: StakeholderProfileFormOptions;
        completeAddress: string;
        disabled?: boolean;
    }>(),
    {
        disabled: false,
    },
);

const NONE = '__none__';

function nullableSelect(field: 'governance_level' | 'transaction_type') {
    return computed<string>({
        get: () => props.form[field] || NONE,
        set: (value: string) => {
            // eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object.
            props.form[field] = value === NONE ? '' : value;
        },
    });
}

const governanceLevelModel = nullableSelect('governance_level');
const transactionTypeModel = nullableSelect('transaction_type');
</script>

<template>
    <div class="grid gap-6 lg:grid-cols-2 items-start">
        <fieldset class="space-y-4 rounded-lg border border-input bg-muted/30 p-4">
            <legend class="px-1 text-base font-medium">Organization</legend>
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="governance_level">Governance level</Label>
                    <Select v-model="governanceLevelModel" :disabled="disabled">
                        <SelectTrigger id="governance_level" class="w-full">
                            <SelectValue placeholder="None" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem :value="NONE">None</SelectItem>
                            <SelectItem v-for="opt in options.governance_level" :key="opt.value" :value="opt.value">
                                {{ opt.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="form.errors.governance_level" />
                </div>
                <div class="grid gap-2">
                    <Label for="ro">Regional Office</Label>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Input id="ro" v-model="form.ro" :disabled="disabled" />
                    <InputError :message="form.errors.ro" />
                </div>
                <div class="grid gap-2">
                    <Label for="sdo">Schools Division Office</Label>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Input id="sdo" v-model="form.sdo" :disabled="disabled" />
                    <InputError :message="form.errors.sdo" />
                </div>
                <div class="grid gap-2">
                    <Label for="school_district">School district</Label>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Input id="school_district" v-model="form.school_district" :disabled="disabled" />
                    <InputError :message="form.errors.school_district" />
                </div>
                <div class="grid gap-2">
                    <Label for="school_name">School name</Label>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Input id="school_name" v-model="form.school_name" :disabled="disabled" />
                    <InputError :message="form.errors.school_name" />
                </div>
                <div class="grid gap-2">
                    <Label for="school_id">School ID</Label>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Input id="school_id" v-model="form.school_id" :disabled="disabled" />
                    <InputError :message="form.errors.school_id" />
                </div>
            </div>
        </fieldset>

        <fieldset class="space-y-4 rounded-lg border border-input bg-muted/30 p-4">
            <legend class="px-1 text-base font-medium">Location</legend>
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="province">Province</Label>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Input id="province" v-model="form.province" :disabled="disabled" />
                    <InputError :message="form.errors.province" />
                </div>
                <div class="grid gap-2">
                    <Label for="city_municipality">City / Municipality</Label>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Input id="city_municipality" v-model="form.city_municipality" :disabled="disabled" />
                    <InputError :message="form.errors.city_municipality" />
                </div>
                <div class="grid gap-2">
                    <Label for="legislative_district">Legislative district</Label>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Input id="legislative_district" v-model="form.legislative_district" :disabled="disabled" />
                    <InputError :message="form.errors.legislative_district" />
                </div>
                <div class="grid gap-2">
                    <Label for="barangay">Barangay</Label>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Input id="barangay" v-model="form.barangay" :disabled="disabled" />
                    <InputError :message="form.errors.barangay" />
                </div>
                <div class="grid gap-2">
                    <Label for="street">Street</Label>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Input id="street" v-model="form.street" :disabled="disabled" />
                    <InputError :message="form.errors.street" />
                </div>
                <div class="grid gap-2">
                    <Label for="psgc">PSGC</Label>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Input id="psgc" v-model="form.psgc" :disabled="disabled" />
                    <InputError :message="form.errors.psgc" />
                </div>
                <div class="grid gap-2 sm:col-span-2">
                    <Label for="complete_address">Complete address</Label>
                    <Input id="complete_address" :model-value="completeAddress" readonly disabled />
                    <p class="text-sm text-muted-foreground">
                        Generated automatically from street, barangay, city/municipality, and province.
                    </p>
                </div>
            </div>
        </fieldset>

        <fieldset class="space-y-4 rounded-lg border border-input bg-muted/30 p-4 lg:col-span-2">
            <legend class="px-1 text-base font-medium">Key personnel</legend>
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="chief_name">RO/SDO Chief / School Head — name</Label>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Input id="chief_name" v-model="form.chief_name" :disabled="disabled" />
                    <InputError :message="form.errors.chief_name" />
                </div>
                <div class="grid gap-2">
                    <Label for="chief_position">Position</Label>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Input id="chief_position" v-model="form.chief_position" :disabled="disabled" />
                    <InputError :message="form.errors.chief_position" />
                </div>
                <div class="grid gap-2">
                    <Label for="chief_email">Email</Label>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Input id="chief_email" v-model="form.chief_email" type="email" :disabled="disabled" />
                    <InputError :message="form.errors.chief_email" />
                </div>
                <div class="grid gap-2">
                    <Label for="chief_mobile">Mobile</Label>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Input id="chief_mobile" v-model="form.chief_mobile" :disabled="disabled" />
                    <InputError :message="form.errors.chief_mobile" />
                </div>

                <div class="grid gap-2">
                    <Label for="admin_staff_name">Administrative Staff / Inventory Clerk — name</Label>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Input id="admin_staff_name" v-model="form.admin_staff_name" :disabled="disabled" />
                    <InputError :message="form.errors.admin_staff_name" />
                </div>
                <div class="grid gap-2">
                    <Label for="admin_staff_position">Position</Label>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Input id="admin_staff_position" v-model="form.admin_staff_position" :disabled="disabled" />
                    <InputError :message="form.errors.admin_staff_position" />
                </div>
                <div class="grid gap-2">
                    <Label for="admin_staff_email">Email</Label>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Input id="admin_staff_email" v-model="form.admin_staff_email" type="email" :disabled="disabled" />
                    <InputError :message="form.errors.admin_staff_email" />
                </div>
                <div class="grid gap-2">
                    <Label for="admin_staff_mobile">Mobile</Label>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Input id="admin_staff_mobile" v-model="form.admin_staff_mobile" :disabled="disabled" />
                    <InputError :message="form.errors.admin_staff_mobile" />
                </div>

                <div class="grid gap-2 sm:col-span-2">
                    <Label for="network_administrator_name">Network administrator</Label>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Input id="network_administrator_name" v-model="form.network_administrator_name" :disabled="disabled" />
                    <InputError :message="form.errors.network_administrator_name" />
                </div>
            </div>
        </fieldset>

        <fieldset class="space-y-4 rounded-lg border border-input bg-muted/30 p-4">
            <legend class="px-1 text-base font-medium">Contact numbers</legend>
            <div class="grid gap-4 sm:grid-cols-3">
                <div class="grid gap-2">
                    <Label for="mobile_1">Mobile 1</Label>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Input id="mobile_1" v-model="form.mobile_1" :disabled="disabled" />
                    <InputError :message="form.errors.mobile_1" />
                </div>
                <div class="grid gap-2">
                    <Label for="mobile_2">Mobile 2</Label>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Input id="mobile_2" v-model="form.mobile_2" :disabled="disabled" />
                    <InputError :message="form.errors.mobile_2" />
                </div>
                <div class="grid gap-2">
                    <Label for="landline">Landline</Label>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Input id="landline" v-model="form.landline" :disabled="disabled" />
                    <InputError :message="form.errors.landline" />
                </div>
            </div>
        </fieldset>

        <fieldset class="space-y-4 rounded-lg border border-input bg-muted/30 p-4">
            <legend class="px-1 text-base font-medium">Geographic coordinates</legend>
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="longitude">Longitude</Label>
                    <!-- eslint-disable vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Input
                        id="longitude"
                        v-model="form.longitude"
                        type="number"
                        step="0.0000001"
                        min="-180"
                        max="180"
                        :disabled="disabled"
                    />
                    <!-- eslint-enable vue/no-mutating-props -->
                    <InputError :message="form.errors.longitude" />
                </div>
                <div class="grid gap-2">
                    <Label for="latitude">Latitude</Label>
                    <!-- eslint-disable vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Input
                        id="latitude"
                        v-model="form.latitude"
                        type="number"
                        step="0.0000001"
                        min="-90"
                        max="90"
                        :disabled="disabled"
                    />
                    <!-- eslint-enable vue/no-mutating-props -->
                    <InputError :message="form.errors.latitude" />
                </div>
            </div>
        </fieldset>

        <fieldset class="space-y-4 rounded-lg border border-input bg-muted/30 p-4 lg:col-span-2">
            <legend class="px-1 text-base font-medium">Accessibility</legend>
            <div class="grid gap-4">
                <fieldset class="grid gap-4">
                    <legend class="mb-2 text-sm leading-none font-medium">Nearby institutions</legend>
                    <CheckboxGroupField
                        :form="form"
                        field="nearby_institutions"
                        :options="options.nearby_institution"
                        id-prefix="nearby_institutions"
                        :disabled="disabled"
                    />
                    <InputError :message="form.errors.nearby_institutions" />
                </fieldset>
                <div class="grid gap-2 sm:max-w-sm">
                    <Label for="nearby_institutions_other">Other nearby institution</Label>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Input id="nearby_institutions_other" v-model="form.nearby_institutions_other" :disabled="disabled" />
                    <InputError :message="form.errors.nearby_institutions_other" />
                </div>

                <div class="grid gap-2 sm:max-w-sm">
                    <Label for="travel_time_to_center_minutes">Travel time to center (minutes)</Label>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Input id="travel_time_to_center_minutes" v-model="form.travel_time_to_center_minutes" type="number" min="0" :disabled="disabled" />
                    <InputError :message="form.errors.travel_time_to_center_minutes" />
                </div>

                <fieldset class="grid gap-4">
                    <legend class="mb-2 text-sm leading-none font-medium">Access paths</legend>
                    <CheckboxGroupField
                        :form="form"
                        field="access_paths"
                        :options="options.type_of_access_road"
                        id-prefix="access_paths"
                        :disabled="disabled"
                    />
                    <InputError :message="form.errors.access_paths" />
                </fieldset>

                <fieldset class="grid gap-4">
                    <legend class="mb-2 text-sm leading-none font-medium">Transportation options</legend>
                    <CheckboxGroupField
                        :form="form"
                        field="transportation_options"
                        :options="options.by_transportation"
                        id-prefix="transportation_options"
                        :disabled="disabled"
                    />
                    <InputError :message="form.errors.transportation_options" />
                </fieldset>
                <div class="grid gap-2 sm:max-w-sm">
                    <Label for="transportation_other">Other transportation option</Label>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Input id="transportation_other" v-model="form.transportation_other" :disabled="disabled" />
                    <InputError :message="form.errors.transportation_other" />
                </div>

                <div class="flex flex-wrap gap-6">
                    <div class="flex items-center gap-3">
                        <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                        <Checkbox id="transportation_difficult" v-model="form.transportation_difficult" :disabled="disabled" />
                        <Label for="transportation_difficult">Transportation is difficult</Label>
                    </div>
                    <div class="flex items-center gap-3">
                        <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                        <Checkbox id="considered_very_remote" v-model="form.considered_very_remote" :disabled="disabled" />
                        <Label for="considered_very_remote">Considered very remote</Label>
                    </div>
                    <div class="flex items-center gap-3">
                        <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                        <Checkbox id="gidca" v-model="form.gidca" :disabled="disabled" />
                        <Label for="gidca">GIDCA (Geographically Isolated, Disadvantaged, and Conflict-Affected)</Label>
                    </div>
                    <div class="flex items-center gap-3">
                        <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                        <Checkbox id="lms" v-model="form.lms" :disabled="disabled" />
                        <Label for="lms">LMS (Last Mile School)</Label>
                    </div>
                </div>

                <div class="grid gap-2">
                    <Label for="remote_context_notes">Remote context notes</Label>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Textarea id="remote_context_notes" v-model="form.remote_context_notes" :disabled="disabled" />
                    <InputError :message="form.errors.remote_context_notes" />
                </div>
            </div>
        </fieldset>

        <fieldset class="space-y-4 rounded-lg border border-input bg-muted/30 p-4">
            <legend class="px-1 text-base font-medium">Community engagement</legend>
            <div class="grid gap-4">
                <fieldset class="grid gap-4">
                    <legend class="mb-2 text-sm leading-none font-medium">Community engagement</legend>
                    <CheckboxGroupField
                        :form="form"
                        field="community_engagement"
                        :options="options.community_engagement"
                        id-prefix="community_engagement"
                        :disabled="disabled"
                    />
                    <InputError :message="form.errors.community_engagement" />
                </fieldset>
                <div class="grid gap-2">
                    <Label for="community_context_remarks">Community context remarks</Label>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Textarea id="community_context_remarks" v-model="form.community_context_remarks" :disabled="disabled" />
                    <InputError :message="form.errors.community_context_remarks" />
                </div>
            </div>
        </fieldset>

        <fieldset class="space-y-4 rounded-lg border border-input bg-muted/30 p-4">
            <legend class="px-1 text-base font-medium">Submission &amp; notes</legend>
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="submitted_at">Submitted at</Label>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Input id="submitted_at" v-model="form.submitted_at" type="datetime-local" :disabled="disabled" />
                    <InputError :message="form.errors.submitted_at" />
                </div>
                <div class="grid gap-2">
                    <Label for="transaction_type">Transaction type</Label>
                    <Select v-model="transactionTypeModel" :disabled="disabled">
                        <SelectTrigger id="transaction_type" class="w-full">
                            <SelectValue placeholder="None" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem :value="NONE">None</SelectItem>
                            <SelectItem v-for="opt in options.transaction_type" :key="opt.value" :value="opt.value">
                                {{ opt.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="form.errors.transaction_type" />
                </div>
                <div class="grid gap-2 sm:col-span-2">
                    <Label for="notes_corrections">Notes / corrections</Label>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Textarea id="notes_corrections" v-model="form.notes_corrections" :disabled="disabled" />
                    <InputError :message="form.errors.notes_corrections" />
                </div>
                <div class="grid gap-2 sm:col-span-2">
                    <Label for="notes_recent_development">Notes on recent development</Label>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Textarea id="notes_recent_development" v-model="form.notes_recent_development" :disabled="disabled" />
                    <InputError :message="form.errors.notes_recent_development" />
                </div>
            </div>
        </fieldset>
    </div>
</template>
