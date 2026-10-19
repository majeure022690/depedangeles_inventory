<script setup lang="ts">
import type { InertiaForm } from '@inertiajs/vue3';
import { computed } from 'vue';
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
import type { IspAccountFormData, IspAccountFormOptions } from '@/types/isp-account';

const props = defineProps<{
    form: InertiaForm<IspAccountFormData>;
    options: IspAccountFormOptions;
}>();

const NONE = '__none__';

function nullableSelect(field: 'rate_connectivity_admin_areas' | 'rate_connectivity_classroom') {
    return computed<string>({
        get: () => props.form[field] || NONE,
        set: (value: string) => {
            // eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object.
            props.form[field] = value === NONE ? '' : value;
        },
    });
}

const rateConnectivityAdminAreasModel = nullableSelect('rate_connectivity_admin_areas');
const rateConnectivityClassroomModel = nullableSelect('rate_connectivity_classroom');
</script>

<template>
    <div class="grid gap-6 lg:grid-cols-2 items-start">
        <fieldset class="space-y-4 rounded-lg border border-input bg-muted/30 p-4">
            <legend class="px-1 text-base font-medium">Provider & billing</legend>
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="isp_provider_id">ISP</Label>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Select v-model="form.isp_provider_id">
                        <SelectTrigger id="isp_provider_id" class="w-full">
                            <SelectValue placeholder="Select an ISP" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="opt in options.isp_provider"
                                :key="opt.value"
                                :value="opt.value"
                            >
                                {{ opt.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="form.errors.isp_provider_id" />
                </div>
                <div class="grid gap-2">
                    <Label for="isp_billing_account_no">Billing account no.</Label>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Input id="isp_billing_account_no" v-model="form.isp_billing_account_no" required />
                    <InputError :message="form.errors.isp_billing_account_no" />
                </div>
                <div class="grid gap-2">
                    <Label for="cost_per_month">Cost per month</Label>
                    <!-- eslint-disable vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Input
                        id="cost_per_month"
                        v-model="form.cost_per_month"
                        type="number"
                        step="0.01"
                        min="0"
                        required
                    />
                    <!-- eslint-enable vue/no-mutating-props -->
                    <InputError :message="form.errors.cost_per_month" />
                </div>
                <div class="grid gap-2 sm:col-span-2">
                    <Label for="package_purchased_inclusion">Package inclusions</Label>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Textarea id="package_purchased_inclusion" v-model="form.package_purchased_inclusion" required />
                    <InputError :message="form.errors.package_purchased_inclusion" />
                </div>
            </div>
        </fieldset>

        <fieldset class="space-y-4 rounded-lg border border-input bg-muted/30 p-4">
            <legend class="px-1 text-base font-medium">Coverage & speed</legend>
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2 sm:col-span-2">
                    <Label for="school_area_coverage">School level coverage</Label>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Select v-model="form.school_area_coverage">
                        <SelectTrigger id="school_area_coverage" class="w-full">
                            <SelectValue placeholder="Select coverage" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="opt in options.school_level_coverage"
                                :key="opt.value"
                                :value="opt.value"
                            >
                                {{ opt.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="form.errors.school_area_coverage" />
                </div>
                <div class="grid gap-2">
                    <Label for="min_speed">Minimum speed (Mbps)</Label>
                    <!-- eslint-disable vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Input id="min_speed" v-model="form.min_speed" type="number" step="0.01" min="0" />
                    <!-- eslint-enable vue/no-mutating-props -->
                    <InputError :message="form.errors.min_speed" />
                </div>
                <div class="grid gap-2">
                    <Label for="max_speed">Maximum speed (Mbps)</Label>
                    <!-- eslint-disable vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Input id="max_speed" v-model="form.max_speed" type="number" step="0.01" min="0" />
                    <!-- eslint-enable vue/no-mutating-props -->
                    <InputError :message="form.errors.max_speed" />
                </div>
            </div>
        </fieldset>

        <fieldset class="space-y-4 rounded-lg border border-input bg-muted/30 p-4">
            <legend class="px-1 text-base font-medium">Subscription & contract</legend>
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="subscription_type">Subscription type</Label>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Select v-model="form.subscription_type">
                        <SelectTrigger id="subscription_type" class="w-full">
                            <SelectValue placeholder="Select a type" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="opt in options.subscription_type"
                                :key="opt.value"
                                :value="opt.value"
                            >
                                {{ opt.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="form.errors.subscription_type" />
                </div>
                <div class="grid gap-2">
                    <Label for="isp_connection_type">Connection type</Label>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Select v-model="form.isp_connection_type">
                        <SelectTrigger id="isp_connection_type" class="w-full">
                            <SelectValue placeholder="Select a connection type" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="opt in options.isp_connection_type"
                                :key="opt.value"
                                :value="opt.value"
                            >
                                {{ opt.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="form.errors.isp_connection_type" />
                </div>
                <div class="grid gap-2">
                    <Label for="contract_start_date">Contract start date</Label>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Input id="contract_start_date" v-model="form.contract_start_date" type="date" />
                    <InputError :message="form.errors.contract_start_date" />
                </div>
                <div class="grid gap-2">
                    <Label for="contract_end_date">Contract end date</Label>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Input id="contract_end_date" v-model="form.contract_end_date" type="date" />
                    <InputError :message="form.errors.contract_end_date" />
                </div>
                <div class="flex items-end gap-2 pb-2">
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Checkbox id="inactive_contract" v-model="form.inactive_contract" />
                    <Label for="inactive_contract">Inactive contract</Label>
                </div>
            </div>
        </fieldset>

        <fieldset class="space-y-4 rounded-lg border border-input bg-muted/30 p-4">
            <legend class="px-1 text-base font-medium">Purpose & acquisition</legend>
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2 sm:col-span-2">
                    <Label for="purpose_of_subscription">Purpose of subscription</Label>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Select v-model="form.purpose_of_subscription">
                        <SelectTrigger id="purpose_of_subscription" class="w-full">
                            <SelectValue placeholder="Select a purpose" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="opt in options.purpose_of_subscription"
                                :key="opt.value"
                                :value="opt.value"
                            >
                                {{ opt.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="form.errors.purpose_of_subscription" />
                </div>
                <div class="grid gap-2">
                    <Label for="mode_of_acquisition">Mode of acquisition</Label>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Select v-model="form.mode_of_acquisition">
                        <SelectTrigger id="mode_of_acquisition" class="w-full">
                            <SelectValue placeholder="Select a mode" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="opt in options.mode_of_acquisition"
                                :key="opt.value"
                                :value="opt.value"
                            >
                                {{ opt.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="form.errors.mode_of_acquisition" />
                </div>
                <div class="grid gap-2">
                    <Label for="source_of_acquisition">Source of acquisition</Label>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Select v-model="form.source_of_acquisition">
                        <SelectTrigger id="source_of_acquisition" class="w-full">
                            <SelectValue placeholder="Select a source" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="opt in options.source_of_acquisition"
                                :key="opt.value"
                                :value="opt.value"
                            >
                                {{ opt.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="form.errors.source_of_acquisition" />
                </div>
                <div class="grid gap-2">
                    <Label for="donor">Donor</Label>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Input id="donor" v-model="form.donor" />
                    <InputError :message="form.errors.donor" />
                </div>
                <div class="grid gap-2">
                    <Label for="fund_source">Source of funds</Label>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Select v-model="form.fund_source">
                        <SelectTrigger id="fund_source" class="w-full">
                            <SelectValue placeholder="Select a fund source" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="opt in options.source_of_funds"
                                :key="opt.value"
                                :value="opt.value"
                            >
                                {{ opt.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="form.errors.fund_source" />
                </div>
            </div>
        </fieldset>

        <fieldset class="space-y-4 rounded-lg border border-input bg-muted/30 p-4">
            <legend class="px-1 text-base font-medium">Access points & area coverage</legend>
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="number_access_points_linked">Access points linked</Label>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Input id="number_access_points_linked" v-model="form.number_access_points_linked" type="number" min="0" />
                    <InputError :message="form.errors.number_access_points_linked" />
                </div>
                <div class="grid gap-2">
                    <Label for="locations_access_points">Access point locations</Label>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Textarea id="locations_access_points" v-model="form.locations_access_points" />
                    <InputError :message="form.errors.locations_access_points" />
                </div>
                <div class="grid gap-2">
                    <Label for="number_admin_area_covered">Admin areas covered</Label>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Input id="number_admin_area_covered" v-model="form.number_admin_area_covered" type="number" min="0" />
                    <InputError :message="form.errors.number_admin_area_covered" />
                </div>
                <div class="grid gap-2">
                    <Label for="rate_connectivity_admin_areas">Admin area connectivity rating</Label>
                    <Select v-model="rateConnectivityAdminAreasModel">
                        <SelectTrigger id="rate_connectivity_admin_areas" class="w-full">
                            <SelectValue placeholder="None" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem :value="NONE">None</SelectItem>
                            <SelectItem
                                v-for="opt in options.signal_quality"
                                :key="opt.value"
                                :value="opt.value"
                            >
                                {{ opt.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="form.errors.rate_connectivity_admin_areas" />
                </div>
                <div class="grid gap-2">
                    <Label for="number_classrooms_covered">Classrooms covered</Label>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Input id="number_classrooms_covered" v-model="form.number_classrooms_covered" type="number" min="0" />
                    <InputError :message="form.errors.number_classrooms_covered" />
                </div>
                <div class="grid gap-2">
                    <Label for="rate_connectivity_classroom">Classroom connectivity rating</Label>
                    <Select v-model="rateConnectivityClassroomModel">
                        <SelectTrigger id="rate_connectivity_classroom" class="w-full">
                            <SelectValue placeholder="None" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem :value="NONE">None</SelectItem>
                            <SelectItem
                                v-for="opt in options.signal_quality"
                                :key="opt.value"
                                :value="opt.value"
                            >
                                {{ opt.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="form.errors.rate_connectivity_classroom" />
                </div>
            </div>
        </fieldset>

        <fieldset class="space-y-4 rounded-lg border border-input bg-muted/30 p-4">
            <legend class="px-1 text-base font-medium">Overall quality</legend>
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="overall_signal_quality">Overall signal quality</Label>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Select v-model="form.overall_signal_quality">
                        <SelectTrigger id="overall_signal_quality" class="w-full">
                            <SelectValue placeholder="Select a signal quality" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="opt in options.signal_quality"
                                :key="opt.value"
                                :value="opt.value"
                            >
                                {{ opt.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="form.errors.overall_signal_quality" />
                </div>
                <div class="grid gap-2">
                    <Label for="rate_isp_service">ISP service rating (1–5)</Label>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Input id="rate_isp_service" v-model="form.rate_isp_service" type="number" min="1" max="5" />
                    <InputError :message="form.errors.rate_isp_service" />
                </div>
            </div>
        </fieldset>
    </div>
</template>
