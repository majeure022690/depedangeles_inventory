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
import type { EquipmentFormData, EquipmentFormOptions } from '@/types/equipment';

const props = defineProps<{
    form: InertiaForm<EquipmentFormData>;
    options: EquipmentFormOptions;
}>();

const NONE = '__none__';

function nullableSelect(field: 'dcp_package') {
    return computed<string>({
        get: () => props.form[field] || NONE,
        set: (value: string) => {
            // eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object.
            props.form[field] = value === NONE ? '' : value;
        },
    });
}

const dcpPackageModel = nullableSelect('dcp_package');
</script>

<template>
    <div class="grid gap-6 lg:grid-cols-2 items-start">
        <fieldset class="space-y-4 rounded-lg border border-input bg-muted/30 p-4">
            <legend class="px-1 text-base font-medium">Identification</legend>
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="property_no">Property number</Label>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Input id="property_no" v-model="form.property_no" required />
                    <InputError :message="form.errors.property_no" />
                </div>
                <div class="grid gap-2">
                    <Label for="old_property_no">Old property number</Label>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Input id="old_property_no" v-model="form.old_property_no" />
                    <InputError :message="form.errors.old_property_no" />
                </div>
                <div class="grid gap-2">
                    <Label for="serial_number">Serial number</Label>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Input id="serial_number" v-model="form.serial_number" />
                    <InputError :message="form.errors.serial_number" />
                </div>
            </div>
        </fieldset>

        <fieldset class="space-y-4 rounded-lg border border-input bg-muted/30 p-4">
            <legend class="px-1 text-base font-medium">Accounting</legend>
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="gl_sl_code">GL/SL code</Label>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Input id="gl_sl_code" v-model="form.gl_sl_code" />
                    <InputError :message="form.errors.gl_sl_code" />
                </div>
                <div class="grid gap-2">
                    <Label for="uacs">UACS</Label>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Input id="uacs" v-model="form.uacs" />
                    <InputError :message="form.errors.uacs" />
                </div>
                <div class="grid gap-2">
                    <Label for="acquisition_cost">Acquisition cost</Label>
                    <!-- eslint-disable vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Input
                        id="acquisition_cost"
                        v-model="form.acquisition_cost"
                        type="number"
                        step="0.01"
                        min="0"
                        required
                    />
                    <!-- eslint-enable vue/no-mutating-props -->
                    <InputError :message="form.errors.acquisition_cost" />
                </div>
                <div class="grid gap-2">
                    <Label for="received_date">Received date</Label>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Input id="received_date" v-model="form.received_date" type="date" />
                    <InputError :message="form.errors.received_date" />
                </div>
                <div class="grid gap-2">
                    <Label for="estimated_useful_life">Estimated useful life (years)</Label>
                    <!-- eslint-disable vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Input
                        id="estimated_useful_life"
                        v-model="form.estimated_useful_life"
                        type="number"
                        min="0"
                    />
                    <!-- eslint-enable vue/no-mutating-props -->
                    <InputError :message="form.errors.estimated_useful_life" />
                </div>
            </div>
        </fieldset>

        <fieldset class="space-y-4 rounded-lg border border-input bg-muted/30 p-4 lg:col-span-2">
            <legend class="px-1 text-base font-medium">Item details</legend>
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="item_type_id">Item</Label>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Select v-model="form.item_type_id">
                        <SelectTrigger id="item_type_id" class="w-full">
                            <SelectValue placeholder="Select an item type" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="opt in options.item_type"
                                :key="opt.value"
                                :value="opt.value"
                            >
                                {{ opt.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="form.errors.item_type_id" />
                </div>
                <div class="grid gap-2">
                    <Label for="uom">Unit of measure</Label>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Select v-model="form.uom">
                        <SelectTrigger id="uom" class="w-full">
                            <SelectValue placeholder="Select a unit" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="opt in options.unit_of_measure"
                                :key="opt.value"
                                :value="opt.value"
                            >
                                {{ opt.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="form.errors.uom" />
                </div>
                <div class="grid gap-2">
                    <Label for="brand_id">Brand/manufacturer</Label>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Select v-model="form.brand_id">
                        <SelectTrigger id="brand_id" class="w-full">
                            <SelectValue placeholder="Select a brand" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="opt in options.brand"
                                :key="opt.value"
                                :value="opt.value"
                            >
                                {{ opt.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="form.errors.brand_id" />
                </div>
                <div class="grid gap-2">
                    <Label for="model">Model</Label>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Input id="model" v-model="form.model" />
                    <InputError :message="form.errors.model" />
                </div>
                <div class="grid gap-2 sm:col-span-2">
                    <Label for="specifications">Specifications</Label>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Textarea id="specifications" v-model="form.specifications" />
                    <InputError :message="form.errors.specifications" />
                </div>
                <div class="flex items-end gap-2 pb-2">
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Checkbox id="non_dcp" v-model="form.non_dcp" />
                    <Label for="non_dcp">Non-DCP</Label>
                </div>
                <div v-if="!form.non_dcp" class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="dcp_package">DCP package</Label>
                        <Select v-model="dcpPackageModel">
                            <SelectTrigger id="dcp_package" class="w-full">
                                <SelectValue placeholder="None" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem :value="NONE">None</SelectItem>
                                <SelectItem
                                    v-for="opt in options.dcp_package"
                                    :key="opt.value"
                                    :value="opt.value"
                                >
                                    {{ opt.label }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="form.errors.dcp_package" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="dcp_year">DCP year</Label>
                        <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                        <Input id="dcp_year" v-model="form.dcp_year" inputmode="numeric" placeholder="YYYY" />
                        <InputError :message="form.errors.dcp_year" />
                    </div>
                </div>
                <div class="grid gap-2">
                    <Label for="equipment_category_id">Category</Label>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Select v-model="form.equipment_category_id">
                        <SelectTrigger id="equipment_category_id" class="w-full">
                            <SelectValue placeholder="Select a category" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="opt in options.category"
                                :key="opt.value"
                                :value="opt.value"
                            >
                                {{ opt.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="form.errors.equipment_category_id" />
                </div>
                <div class="grid gap-2">
                    <Label for="equipment_classification_id">Classification</Label>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Select v-model="form.equipment_classification_id">
                        <SelectTrigger id="equipment_classification_id" class="w-full">
                            <SelectValue placeholder="Select a classification" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="opt in options.classification"
                                :key="opt.value"
                                :value="opt.value"
                            >
                                {{ opt.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="form.errors.equipment_classification_id" />
                </div>
            </div>
        </fieldset>

        <fieldset class="space-y-4 rounded-lg border border-input bg-muted/30 p-4">
            <legend class="px-1 text-base font-medium">Acquisition</legend>
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="mode_acquisition">Mode of acquisition</Label>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Select v-model="form.mode_acquisition">
                        <SelectTrigger id="mode_acquisition" class="w-full">
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
                    <InputError :message="form.errors.mode_acquisition" />
                </div>
                <div class="grid gap-2">
                    <Label for="source_acquisition">Source of acquisition</Label>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Select v-model="form.source_acquisition">
                        <SelectTrigger id="source_acquisition" class="w-full">
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
                    <InputError :message="form.errors.source_acquisition" />
                </div>
                <div class="grid gap-2">
                    <Label for="donor">Donor</Label>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Input id="donor" v-model="form.donor" />
                    <InputError :message="form.errors.donor" />
                </div>
                <div class="grid gap-2">
                    <Label for="source_fund">Source of funds</Label>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Select v-model="form.source_fund">
                        <SelectTrigger id="source_fund" class="w-full">
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
                    <InputError :message="form.errors.source_fund" />
                </div>
                <div class="grid gap-2">
                    <Label for="allotment_class">Allotment class</Label>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Select v-model="form.allotment_class">
                        <SelectTrigger id="allotment_class" class="w-full">
                            <SelectValue placeholder="Select an allotment class" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="opt in options.allotment_class"
                                :key="opt.value"
                                :value="opt.value"
                            >
                                {{ opt.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="form.errors.allotment_class" />
                </div>
            </div>
        </fieldset>

        <fieldset class="space-y-4 rounded-lg border border-input bg-muted/30 p-4">
            <legend class="px-1 text-base font-medium">Warranty & supplier</legend>
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="flex items-end gap-2 pb-2">
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Checkbox id="under_warranty" v-model="form.under_warranty" />
                    <Label for="under_warranty">Under warranty</Label>
                </div>
                <div v-if="form.under_warranty" class="grid gap-2">
                    <Label for="end_warranty_date">Warranty end date</Label>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Input id="end_warranty_date" v-model="form.end_warranty_date" type="date" />
                    <InputError :message="form.errors.end_warranty_date" />
                </div>
                <div class="grid gap-2 sm:col-span-2">
                    <Label for="supplier">Supplier</Label>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Input id="supplier" v-model="form.supplier" />
                    <InputError :message="form.errors.supplier" />
                </div>
            </div>
        </fieldset>

        <fieldset class="space-y-4 rounded-lg border border-input bg-muted/30 p-4">
            <legend class="px-1 text-base font-medium">Condition & location</legend>
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="pm_plan">PM plan</Label>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Input id="pm_plan" v-model="form.pm_plan" />
                    <InputError :message="form.errors.pm_plan" />
                </div>
                <div class="grid gap-2 sm:col-span-2">
                    <Label for="equipment_location">Location</Label>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Textarea id="equipment_location" v-model="form.equipment_location" />
                    <InputError :message="form.errors.equipment_location" />
                </div>
                <div class="flex items-end gap-2 pb-2">
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Checkbox id="non_functional" v-model="form.non_functional" />
                    <Label for="non_functional">Non-functional</Label>
                </div>
                <div class="grid gap-2">
                    <Label for="equipment_condition_id">Condition</Label>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Select v-model="form.equipment_condition_id">
                        <SelectTrigger id="equipment_condition_id" class="w-full">
                            <SelectValue placeholder="Select a condition" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="opt in options.condition"
                                :key="opt.value"
                                :value="opt.value"
                            >
                                {{ opt.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="form.errors.equipment_condition_id" />
                </div>
                <div class="grid gap-2">
                    <Label for="disposition_status">Disposition status</Label>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Select v-model="form.disposition_status">
                        <SelectTrigger id="disposition_status" class="w-full">
                            <SelectValue placeholder="Select a disposition status" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="opt in options.disposition_status"
                                :key="opt.value"
                                :value="opt.value"
                            >
                                {{ opt.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="form.errors.disposition_status" />
                </div>
                <div class="grid gap-2 sm:col-span-2">
                    <Label for="remarks">Remarks</Label>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Textarea id="remarks" v-model="form.remarks" />
                    <InputError :message="form.errors.remarks" />
                </div>
            </div>
        </fieldset>
    </div>
</template>
