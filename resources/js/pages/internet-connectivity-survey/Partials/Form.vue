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
import type {
    InternetConnectivitySurveyFormData,
    InternetConnectivitySurveyFormOptions,
} from '@/types/internet-connectivity-survey';

const props = withDefaults(
    defineProps<{
        form: InertiaForm<InternetConnectivitySurveyFormData>;
        options: InternetConnectivitySurveyFormOptions;
        disabled?: boolean;
    }>(),
    {
        disabled: false,
    },
);

const NONE = '__none__';

const mobileDataQualityModel = computed<string>({
    get: () => props.form.mobile_data_quality || NONE,
    set: (value: string) => {
        // eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object.
        props.form.mobile_data_quality = value === NONE ? '' : value;
    },
});
</script>

<template>
    <div class="grid gap-6 lg:grid-cols-2 items-start">
        <fieldset class="space-y-4 rounded-lg border border-input bg-muted/30 p-4">
            <legend class="px-1 text-base font-medium">ISP availability</legend>
            <div class="grid gap-4">
                <div class="flex items-center gap-3">
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Checkbox id="has_isp_in_area" v-model="form.has_isp_in_area" :disabled="disabled" />
                    <Label for="has_isp_in_area">An ISP is available in the area</Label>
                </div>
                <fieldset class="grid gap-4">
                    <legend class="mb-2 text-sm leading-none font-medium">Available ISPs</legend>
                    <CheckboxGroupField
                        :form="form"
                        field="available_isps"
                        :options="options.isp_provider"
                        id-prefix="available_isps"
                        :disabled="disabled"
                    />
                    <InputError :message="form.errors.available_isps" />
                </fieldset>
                <div class="grid gap-2 sm:max-w-sm">
                    <Label for="available_isps_other">Other available ISP</Label>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Input id="available_isps_other" v-model="form.available_isps_other" :disabled="disabled" />
                    <InputError :message="form.errors.available_isps_other" />
                </div>
            </div>
        </fieldset>

        <fieldset class="space-y-4 rounded-lg border border-input bg-muted/30 p-4">
            <legend class="px-1 text-base font-medium">Mobile connectivity</legend>
            <div class="grid gap-4">
                <fieldset class="grid gap-4">
                    <legend class="mb-2 text-sm leading-none font-medium">Mobile signal types</legend>
                    <CheckboxGroupField
                        :form="form"
                        field="mobile_signal_types"
                        :options="options.mobile_network_signal"
                        id-prefix="mobile_signal_types"
                        :disabled="disabled"
                    />
                    <InputError :message="form.errors.mobile_signal_types" />
                </fieldset>
                <div class="flex items-center gap-3">
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Checkbox id="has_mobile_data_connectivity" v-model="form.has_mobile_data_connectivity" :disabled="disabled" />
                    <Label for="has_mobile_data_connectivity">Has mobile data connectivity</Label>
                </div>
                <div class="grid gap-2 sm:max-w-sm">
                    <Label for="mobile_data_quality">Mobile data quality</Label>
                    <Select v-model="mobileDataQualityModel" :disabled="disabled">
                        <SelectTrigger id="mobile_data_quality" class="w-full">
                            <SelectValue placeholder="None" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem :value="NONE">None</SelectItem>
                            <SelectItem v-for="opt in options.signal_quality" :key="opt.value" :value="opt.value">
                                {{ opt.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="form.errors.mobile_data_quality" />
                </div>
            </div>
        </fieldset>

        <fieldset class="space-y-4 rounded-lg border border-input bg-muted/30 p-4">
            <legend class="px-1 text-base font-medium">Subscription details</legend>
            <div class="grid gap-4">
                <div class="flex items-center gap-3">
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Checkbox id="subscribes_to_isp" v-model="form.subscribes_to_isp" :disabled="disabled" />
                    <Label for="subscribes_to_isp">Currently subscribes to an ISP</Label>
                </div>
                <fieldset class="grid gap-4">
                    <legend class="mb-2 text-sm leading-none font-medium">Subscribed ISPs</legend>
                    <CheckboxGroupField
                        :form="form"
                        field="subscribed_isps"
                        :options="options.isp_provider"
                        id-prefix="subscribed_isps"
                        :disabled="disabled"
                    />
                    <InputError :message="form.errors.subscribed_isps" />
                </fieldset>
                <div class="grid gap-2">
                    <Label for="no_subscription_reason">Reason for no subscription</Label>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Textarea id="no_subscription_reason" v-model="form.no_subscription_reason" :disabled="disabled" />
                    <InputError :message="form.errors.no_subscription_reason" />
                </div>
                <div class="flex items-center gap-3">
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Checkbox id="dict_free_wifi_recipient" v-model="form.dict_free_wifi_recipient" :disabled="disabled" />
                    <Label for="dict_free_wifi_recipient">DICT Free Wi-Fi recipient</Label>
                </div>
                <div class="grid gap-2 sm:max-w-sm">
                    <Label for="dict_free_wifi_rating">DICT Free Wi-Fi rating (1–5)</Label>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Input id="dict_free_wifi_rating" v-model="form.dict_free_wifi_rating" type="number" min="1" max="5" :disabled="disabled" />
                    <InputError :message="form.errors.dict_free_wifi_rating" />
                </div>
            </div>
        </fieldset>

        <fieldset class="space-y-4 rounded-lg border border-input bg-muted/30 p-4">
            <legend class="px-1 text-base font-medium">Coverage &amp; bandwidth</legend>
            <div class="grid gap-4">
                <fieldset class="grid gap-4">
                    <legend class="mb-2 text-sm leading-none font-medium">Coverage areas</legend>
                    <CheckboxGroupField
                        :form="form"
                        field="coverage_areas"
                        :options="options.coverage_area"
                        id-prefix="coverage_areas"
                        :disabled="disabled"
                    />
                    <InputError :message="form.errors.coverage_areas" />
                </fieldset>
                <div class="grid gap-2 sm:max-w-sm">
                    <Label for="coverage_areas_other">Other coverage area</Label>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Input id="coverage_areas_other" v-model="form.coverage_areas_other" :disabled="disabled" />
                    <InputError :message="form.errors.coverage_areas_other" />
                </div>
                <div class="flex items-center gap-3">
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Checkbox id="has_sufficient_bandwidth" v-model="form.has_sufficient_bandwidth" :disabled="disabled" />
                    <Label for="has_sufficient_bandwidth">Has sufficient bandwidth</Label>
                </div>
                <div class="grid gap-2">
                    <Label for="insufficient_bandwidth_explanation">Insufficient bandwidth explanation</Label>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Textarea id="insufficient_bandwidth_explanation" v-model="form.insufficient_bandwidth_explanation" :disabled="disabled" />
                    <InputError :message="form.errors.insufficient_bandwidth_explanation" />
                </div>
                <div class="grid gap-2 sm:max-w-sm">
                    <Label for="rooms_other_use">Rooms for other use</Label>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Input id="rooms_other_use" v-model="form.rooms_other_use" type="number" min="0" :disabled="disabled" />
                    <InputError :message="form.errors.rooms_other_use" />
                </div>
            </div>
        </fieldset>

        <fieldset class="space-y-4 rounded-lg border border-input bg-muted/30 p-4">
            <legend class="px-1 text-base font-medium">Electricity</legend>
            <div class="grid gap-4">
                <div class="flex items-center gap-3">
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Checkbox id="has_electricity_source" v-model="form.has_electricity_source" :disabled="disabled" />
                    <Label for="has_electricity_source">Has an electricity source</Label>
                </div>
                <fieldset class="grid gap-4">
                    <legend class="mb-2 text-sm leading-none font-medium">Electricity sources</legend>
                    <CheckboxGroupField
                        :form="form"
                        field="electricity_sources"
                        :options="options.source_of_electricity"
                        id-prefix="electricity_sources"
                        :disabled="disabled"
                    />
                    <InputError :message="form.errors.electricity_sources" />
                </fieldset>
                <div class="flex flex-wrap gap-6">
                    <div class="flex items-center gap-3">
                        <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                        <Checkbox id="primarily_solar_powered" v-model="form.primarily_solar_powered" :disabled="disabled" />
                        <Label for="primarily_solar_powered">Primarily solar-powered</Label>
                    </div>
                    <div class="flex items-center gap-3">
                        <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                        <Checkbox id="frequent_brownouts" v-model="form.frequent_brownouts" :disabled="disabled" />
                        <Label for="frequent_brownouts">Frequent brownouts</Label>
                    </div>
                </div>
            </div>
        </fieldset>
    </div>
</template>
