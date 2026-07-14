<script setup lang="ts">
import type { InertiaForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import FieldHint from '@/components/FieldHint.vue';
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
                    <div class="flex items-center gap-1.5">
                        <Label for="property_no">Property number</Label>
                        <FieldHint
                            for="property_no"
                            label="Property number"
                            text="Enter the equipment's property number following the official format. For newly acquired items or existing properties without an assigned property number, please coordinate with the Asset Management Office for the proper issuance of a new property number."
                        />
                    </div>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Input id="property_no" v-model="form.property_no" required aria-describedby="property_no-hint" />
                    <InputError :message="form.errors.property_no" />
                </div>
                <div class="grid gap-2">
                    <div class="flex items-center gap-1.5">
                        <Label for="old_property_no">Old property number</Label>
                        <FieldHint
                            for="old_property_no"
                            label="Old property number"
                            text="Enter the equipment's old or previous property number, if applicable (e.g., when the item was originally part of a bundled set and later separated into standalone components such as a keyboard, monitor, mouse, or RAM from the CPU)."
                        />
                    </div>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Input id="old_property_no" v-model="form.old_property_no" aria-describedby="old_property_no-hint" />
                    <InputError :message="form.errors.old_property_no" />
                </div>
                <div class="grid gap-2">
                    <div class="flex items-center gap-1.5">
                        <Label for="serial_number">Serial number</Label>
                        <FieldHint for="serial_number" label="Serial number" text="Input the equipment's Serial number" />
                    </div>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Input id="serial_number" v-model="form.serial_number" aria-describedby="serial_number-hint" />
                    <InputError :message="form.errors.serial_number" />
                </div>
            </div>
        </fieldset>

        <fieldset class="space-y-4 rounded-lg border border-input bg-muted/30 p-4">
            <legend class="px-1 text-base font-medium">Accounting</legend>
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <div class="flex items-center gap-1.5">
                        <Label for="gl_sl_code">GL/SL code</Label>
                        <FieldHint
                            for="gl_sl_code"
                            label="GL/SL code"
                            text="Input the Subsidiary Ledger Chart of Accounts code assigned to the equipment. Kindly coordinate with your Accounting office for assistance. Please refer to https://uacs.gov.ph/resources/uacs/object-code/chart-of-accounts"
                        />
                    </div>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Input id="gl_sl_code" v-model="form.gl_sl_code" aria-describedby="gl_sl_code-hint" />
                    <InputError :message="form.errors.gl_sl_code" />
                </div>
                <div class="grid gap-2">
                    <div class="flex items-center gap-1.5">
                        <Label for="uacs">UACS</Label>
                        <FieldHint
                            for="uacs"
                            label="UACS"
                            text="Input the UACS code assigned to the equipment. Kindly coordinate with your Accounting office for assistance. Please refer to https://uacs.gov.ph/resources/uacs/object-code/sub-object"
                        />
                    </div>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Input id="uacs" v-model="form.uacs" aria-describedby="uacs-hint" />
                    <InputError :message="form.errors.uacs" />
                </div>
                <div class="grid gap-2">
                    <div class="flex items-center gap-1.5">
                        <Label for="acquisition_cost">Acquisition cost</Label>
                        <FieldHint
                            for="acquisition_cost"
                            label="Acquisition cost"
                            text="Input the cost of purchase or value of the equipment. Please refer to the Purchase Order (PO) or related documents for reference."
                        />
                    </div>
                    <!-- eslint-disable vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Input
                        id="acquisition_cost"
                        v-model="form.acquisition_cost"
                        type="number"
                        step="0.01"
                        min="0"
                        required
                        aria-describedby="acquisition_cost-hint"
                    />
                    <!-- eslint-enable vue/no-mutating-props -->
                    <InputError :message="form.errors.acquisition_cost" />
                </div>
                <div class="grid gap-2">
                    <div class="flex items-center gap-1.5">
                        <Label for="received_date">Received date</Label>
                        <FieldHint
                            for="received_date"
                            label="Received date"
                            text="Input when the equipment was acquired in the 'MM/DD/YYYY' date format."
                        />
                    </div>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Input id="received_date" v-model="form.received_date" type="date" aria-describedby="received_date-hint" />
                    <InputError :message="form.errors.received_date" />
                </div>
                <div class="grid gap-2">
                    <div class="flex items-center gap-1.5">
                        <Label for="estimated_useful_life">Estimated useful life (years)</Label>
                        <FieldHint
                            for="estimated_useful_life"
                            label="Estimated useful life"
                            text="Input the equipment's estimated useful life (only accepts whole number). Kindly seek assistance from your Accounting office."
                        />
                    </div>
                    <!-- eslint-disable vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Input
                        id="estimated_useful_life"
                        v-model="form.estimated_useful_life"
                        type="number"
                        min="0"
                        aria-describedby="estimated_useful_life-hint"
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
                    <div class="flex items-center gap-1.5">
                        <Label for="item_type_id">Item</Label>
                        <FieldHint
                            for="item_type_id"
                            label="Item"
                            text="Select the appropriate option from the dropdown list under Item Selection, choosing from Device Type, Equipment, Hardware, Software, or Peripherals."
                        />
                    </div>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Select v-model="form.item_type_id">
                        <SelectTrigger id="item_type_id" class="w-full" aria-describedby="item_type_id-hint">
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
                    <div class="flex items-center gap-1.5">
                        <Label for="uom">Unit of measure</Label>
                        <FieldHint
                            for="uom"
                            label="Unit of measure"
                            text="Select the appropriate unit of measure from the dropdown list."
                        />
                    </div>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Select v-model="form.uom">
                        <SelectTrigger id="uom" class="w-full" aria-describedby="uom-hint">
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
                    <div class="flex items-center gap-1.5">
                        <Label for="brand_id">Brand/manufacturer</Label>
                        <FieldHint for="brand_id" label="Brand/manufacturer" text="Choose the Brand/Manufacturer from the dropdown." />
                    </div>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Select v-model="form.brand_id">
                        <SelectTrigger id="brand_id" class="w-full" aria-describedby="brand_id-hint">
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
                    <div class="flex items-center gap-1.5">
                        <Label for="model">Model</Label>
                        <FieldHint for="model" label="Model" text="Input the equipment's Model Name or Number." />
                    </div>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Input id="model" v-model="form.model" aria-describedby="model-hint" />
                    <InputError :message="form.errors.model" />
                </div>
                <div class="grid gap-2 sm:col-span-2">
                    <div class="flex items-center gap-1.5">
                        <Label for="specifications">Specifications</Label>
                        <FieldHint
                            for="specifications"
                            label="Specifications"
                            text="Enter the equipment's detailed specifications. Refer to the Delivery Receipt (DR), Property Acknowledgment Receipt (PAR), Inventory Custodian Slip (ICS), Inspection and Acceptance Report (IAR), or other relevant documents."
                        />
                    </div>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Textarea id="specifications" v-model="form.specifications" aria-describedby="specifications-hint" />
                    <InputError :message="form.errors.specifications" />
                </div>
                <div class="flex items-end gap-2 pb-2">
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Checkbox id="non_dcp" v-model="form.non_dcp" aria-describedby="non_dcp-hint" />
                    <Label for="non_dcp">Non-DCP</Label>
                    <FieldHint
                        for="non_dcp"
                        label="Non-DCP"
                        text="Checkbox to identify whether an equipment is DCP or Non-DCP"
                    />
                </div>
                <div v-if="!form.non_dcp" class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <div class="flex items-center gap-1.5">
                            <Label for="dcp_package">DCP package</Label>
                            <FieldHint for="dcp_package" label="DCP package" text="Input the Package Name of the DCP" />
                        </div>
                        <Select v-model="dcpPackageModel">
                            <SelectTrigger id="dcp_package" class="w-full" aria-describedby="dcp_package-hint">
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
                        <div class="flex items-center gap-1.5">
                            <Label for="dcp_year">DCP year</Label>
                            <FieldHint for="dcp_year" label="DCP year" text="Input the Year of the DCP Package the item belongs to" />
                        </div>
                        <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                        <Input id="dcp_year" v-model="form.dcp_year" inputmode="numeric" placeholder="YYYY" aria-describedby="dcp_year-hint" />
                        <InputError :message="form.errors.dcp_year" />
                    </div>
                </div>
                <div class="grid gap-2">
                    <div class="flex items-center gap-1.5">
                        <Label for="equipment_category_id">Category</Label>
                        <FieldHint
                            for="equipment_category_id"
                            label="Category"
                            text="Select the equipment's Category from the dropdown. An item is considered a High-Value Equipment if it costs ₱50,000 or above, and a Low-Value Equipment if it costs below ₱50,000."
                        />
                    </div>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Select v-model="form.equipment_category_id">
                        <SelectTrigger id="equipment_category_id" class="w-full" aria-describedby="equipment_category_id-hint">
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
                    <div class="flex items-center gap-1.5">
                        <Label for="equipment_classification_id">Classification</Label>
                        <FieldHint
                            for="equipment_classification_id"
                            label="Classification"
                            text="Select the appropriate Classification from the dropdown. For now, the focus is limited to Machinery and Equipment for Information and Communication Technology (ICT)."
                        />
                    </div>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Select v-model="form.equipment_classification_id">
                        <SelectTrigger id="equipment_classification_id" class="w-full" aria-describedby="equipment_classification_id-hint">
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
                    <div class="flex items-center gap-1.5">
                        <Label for="mode_acquisition">Mode of acquisition</Label>
                        <FieldHint
                            for="mode_acquisition"
                            label="Mode of acquisition"
                            text="Select from the dropdown whether the equipment was acquired through a DepEd purchase or received as a donation."
                        />
                    </div>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Select v-model="form.mode_acquisition">
                        <SelectTrigger id="mode_acquisition" class="w-full" aria-describedby="mode_acquisition-hint">
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
                    <div class="flex items-center gap-1.5">
                        <Label for="source_acquisition">Source of acquisition</Label>
                        <FieldHint
                            for="source_acquisition"
                            label="Source of acquisition"
                            text="From the dropdown, select the source of acquisition (e.g., Central Office, LGU, PTA, etc.)."
                        />
                    </div>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Select v-model="form.source_acquisition">
                        <SelectTrigger id="source_acquisition" class="w-full" aria-describedby="source_acquisition-hint">
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
                    <div class="flex items-center gap-1.5">
                        <Label for="donor">Donor</Label>
                        <FieldHint
                            for="donor"
                            label="Donor"
                            text="If acquisition is through Donation, please put the Name of the Institution (i.e. Company, Association, Organization, Agency or LGU), else please leave it blank."
                        />
                    </div>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Input id="donor" v-model="form.donor" aria-describedby="donor-hint" />
                    <InputError :message="form.errors.donor" />
                </div>
                <div class="grid gap-2">
                    <div class="flex items-center gap-1.5">
                        <Label for="source_fund">Source of funds</Label>
                        <FieldHint for="source_fund" label="Source of funds" text="Select the corresponding source of funds from the dropdown." />
                    </div>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Select v-model="form.source_fund">
                        <SelectTrigger id="source_fund" class="w-full" aria-describedby="source_fund-hint">
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
                    <div class="flex items-center gap-1.5">
                        <Label for="allotment_class">Allotment class</Label>
                        <FieldHint for="allotment_class" label="Allotment class" text="Select the corresponding Allotment Class from the dropdown." />
                    </div>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Select v-model="form.allotment_class">
                        <SelectTrigger id="allotment_class" class="w-full" aria-describedby="allotment_class-hint">
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
                    <Checkbox id="under_warranty" v-model="form.under_warranty" aria-describedby="under_warranty-hint" />
                    <Label for="under_warranty">Under warranty</Label>
                    <FieldHint
                        for="under_warranty"
                        label="Under warranty"
                        text="Use this checkbox to mark the equipment's warranty status."
                    />
                </div>
                <div v-if="form.under_warranty" class="grid gap-2">
                    <div class="flex items-center gap-1.5">
                        <Label for="end_warranty_date">Warranty end date</Label>
                        <FieldHint
                            for="end_warranty_date"
                            label="Warranty end date"
                            text="Input the End of Warranty in the 'MM/DD/YYYY' date format"
                        />
                    </div>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Input id="end_warranty_date" v-model="form.end_warranty_date" type="date" aria-describedby="end_warranty_date-hint" />
                    <InputError :message="form.errors.end_warranty_date" />
                </div>
                <div class="grid gap-2 sm:col-span-2">
                    <div class="flex items-center gap-1.5">
                        <Label for="supplier">Supplier</Label>
                        <FieldHint for="supplier" label="Supplier" text="Input the name of the Supplier / Provider or Distributor of the equipment" />
                    </div>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Input id="supplier" v-model="form.supplier" aria-describedby="supplier-hint" />
                    <InputError :message="form.errors.supplier" />
                </div>
            </div>
        </fieldset>

        <fieldset class="space-y-4 rounded-lg border border-input bg-muted/30 p-4">
            <legend class="px-1 text-base font-medium">Condition & location</legend>
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <div class="flex items-center gap-1.5">
                        <Label for="pm_plan">PM plan</Label>
                        <FieldHint
                            for="pm_plan"
                            label="PM plan"
                            text="Enter the item number as referenced in the approved PPMP document. If the item/equipment was received through donation or not directly purchased by the stakeholder, leave this field blank."
                        />
                    </div>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Input id="pm_plan" v-model="form.pm_plan" aria-describedby="pm_plan-hint" />
                    <InputError :message="form.errors.pm_plan" />
                </div>
                <div class="grid gap-2 sm:col-span-2">
                    <div class="flex items-center gap-1.5">
                        <Label for="equipment_location">Location</Label>
                        <FieldHint
                            for="equipment_location"
                            label="Location"
                            text="Enter the location where the equipment is installed or primarily used (e.g., Access Point installed in School Annex). For movable or mobile assets, indicate the designated office, or leave this field blank if not applicable."
                        />
                    </div>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Textarea id="equipment_location" v-model="form.equipment_location" aria-describedby="equipment_location-hint" />
                    <InputError :message="form.errors.equipment_location" />
                </div>
                <div class="flex items-end gap-2 pb-2">
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Checkbox id="non_functional" v-model="form.non_functional" aria-describedby="non_functional-hint" />
                    <Label for="non_functional">Non-functional</Label>
                    <FieldHint
                        for="non_functional"
                        label="Non-functional"
                        text="Use this checkbox to mark the equipment as Functional or Non-Functional."
                    />
                </div>
                <div class="grid gap-2">
                    <div class="flex items-center gap-1.5">
                        <Label for="equipment_condition_id">Condition</Label>
                        <FieldHint
                            for="equipment_condition_id"
                            label="Condition"
                            text="Select the equipment's condition from the dropdown. Additional details, such as missing accessories, broken parts, non-functional features, or items needing repair, may be provided in the Remarks column."
                        />
                    </div>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Select v-model="form.equipment_condition_id">
                        <SelectTrigger id="equipment_condition_id" class="w-full" aria-describedby="equipment_condition_id-hint">
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
                    <div class="flex items-center gap-1.5">
                        <Label for="disposition_status">Disposition status</Label>
                        <FieldHint
                            for="disposition_status"
                            label="Disposition status"
                            text="Select the equipment's Accountability or Disposition Status from the dropdown: Normal, Transferred, Stolen, Lost, Damaged due to calamity, or For Disposal. Additional details may be provided in the Remarks column."
                        />
                    </div>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Select v-model="form.disposition_status">
                        <SelectTrigger id="disposition_status" class="w-full" aria-describedby="disposition_status-hint">
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
                    <div class="flex items-center gap-1.5">
                        <Label for="remarks">Remarks</Label>
                        <FieldHint
                            for="remarks"
                            label="Remarks"
                            text="This column may include the technical assessment report, evaluation findings, recommendations, justifications, or other relevant information/documents related to the equipment. Include additional notes such as missing accessories, broken parts, non-functional features, repair needs, repair history, or if the item is unusable or unserviceable. For damaged equipment, indicate if the cause is due to natural disasters (e.g., floods, earthquakes, typhoons). The information provided here will serve as justification for any unknown or unidentified details, including document reference numbers, the accountable officer or custodian, or other information that cannot be determined by the user."
                        />
                    </div>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Textarea id="remarks" v-model="form.remarks" aria-describedby="remarks-hint" />
                    <InputError :message="form.errors.remarks" />
                </div>
            </div>
        </fieldset>
    </div>
</template>
