<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import EquipmentForm from '@/pages/equipment/Partials/Form.vue';
import equipment from '@/routes/equipment';
import type { EquipmentFormData, EquipmentFormOptions } from '@/types/equipment';

const props = defineProps<{
    options: EquipmentFormOptions;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Equipment', href: equipment.index() },
            { title: 'Add equipment', href: equipment.create() },
        ],
    },
});

// Tier 1 reference-table fields default to 0 (not a valid row id — the same
// "unset" sentinel role the empty string used to play), so the <Select>
// shows its placeholder until the user picks a real option.
const form = useForm<EquipmentFormData>({
    property_no: '',
    old_property_no: '',
    serial_number: '',
    item_type_id: 0,
    uom: '',
    brand_id: 0,
    model: '',
    specifications: '',
    non_dcp: false,
    dcp_package: '',
    dcp_year: '',
    equipment_category_id: 0,
    equipment_classification_id: 0,
    gl_sl_code: '',
    uacs: '',
    acquisition_cost: '',
    received_date: '',
    estimated_useful_life: '',
    mode_acquisition: '',
    source_acquisition: '',
    donor: '',
    source_fund: '',
    allotment_class: '',
    pm_plan: '',
    equipment_location: '',
    non_functional: false,
    equipment_condition_id: 0,
    disposition_status: '',
    remarks: '',
    under_warranty: false,
    end_warranty_date: '',
    supplier: '',
});

function submit() {
    form.post(equipment.store.url());
}
</script>

<template>
    <Head title="Add equipment" />

    <div class="flex flex-col gap-6 p-4">
        <Heading
            title="Add equipment"
            description="Register a new equipment item. Accountability is assigned afterward by logging a transaction."
        />

        <form class="space-y-8" @submit.prevent="submit">
            <EquipmentForm :form="form" :options="props.options" />

            <div class="flex items-center gap-4">
                <Button type="submit" :disabled="form.processing" data-test="save-equipment-button">
                    <Spinner v-if="form.processing" />
                    Save equipment
                </Button>
                <Link :href="equipment.index()">
                    <Button type="button" variant="secondary">Cancel</Button>
                </Link>
            </div>
        </form>
    </div>
</template>
