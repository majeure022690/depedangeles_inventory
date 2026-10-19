<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Download, Printer } from '@lucide/vue';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Spinner } from '@/components/ui/spinner';
import EquipmentForm from '@/pages/equipment/Partials/Form.vue';
import TransactionLog from '@/pages/equipment/Partials/TransactionLog.vue';
import * as equipmentRoutes from '@/routes/equipment';
import type {
    EquipmentFormData,
    EquipmentFormOptions,
    EquipmentFull,
    EquipmentTransactionFormOptions,
} from '@/types/equipment';
import type { LookupOption } from '@/types/lookup';

const props = defineProps<{
    equipment: EquipmentFull;
    options: EquipmentFormOptions;
    transactionOptions: EquipmentTransactionFormOptions;
    personnelOptions: LookupOption[];
}>();

// property_no is null for equipment still pending official issuance —
// falls back to a clear placeholder instead of the literal string "null"
// appearing in the page title/heading.
const displayPropertyNo = computed(() => props.equipment.property_no ?? 'Pending property no.');

// Note: defineOptions() is hoisted to module scope at compile time, so it
// cannot reference `props` (route params like the equipment id are only
// known at runtime) — the trailing crumb below intentionally links back to
// the index rather than to this exact record.
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Equipment', href: equipmentRoutes.index() },
            { title: 'Edit equipment', href: equipmentRoutes.index() },
        ],
    },
});

const form = useForm<EquipmentFormData>({
    property_no: props.equipment.property_no ?? '',
    old_property_no: props.equipment.old_property_no ?? '',
    serial_number: props.equipment.serial_number ?? '',
    item_type_id: props.equipment.item_type_id,
    uom: props.equipment.uom,
    brand_id: props.equipment.brand_id,
    model: props.equipment.model ?? '',
    specifications: props.equipment.specifications ?? '',
    non_dcp: props.equipment.non_dcp,
    dcp_package: props.equipment.dcp_package ?? '',
    dcp_year: props.equipment.dcp_year ? String(props.equipment.dcp_year) : '',
    equipment_category_id: props.equipment.equipment_category_id,
    equipment_classification_id: props.equipment.equipment_classification_id,
    gl_sl_code: props.equipment.gl_sl_code ?? '',
    uacs: props.equipment.uacs ?? '',
    acquisition_cost: props.equipment.acquisition_cost !== null ? String(props.equipment.acquisition_cost) : '',
    received_date: props.equipment.received_date ?? '',
    estimated_useful_life: props.equipment.estimated_useful_life
        ? String(props.equipment.estimated_useful_life)
        : '',
    mode_acquisition: props.equipment.mode_acquisition,
    source_acquisition: props.equipment.source_acquisition,
    donor: props.equipment.donor ?? '',
    source_fund: props.equipment.source_fund,
    allotment_class: props.equipment.allotment_class,
    pm_plan: props.equipment.pm_plan ?? '',
    equipment_location: props.equipment.equipment_location ?? '',
    non_functional: props.equipment.non_functional,
    equipment_condition_id: props.equipment.equipment_condition_id,
    disposition_status: props.equipment.disposition_status,
    remarks: props.equipment.remarks ?? '',
    under_warranty: props.equipment.under_warranty,
    end_warranty_date: props.equipment.end_warranty_date ?? '',
    supplier: props.equipment.supplier ?? '',
});

function submit() {
    form.put(equipmentRoutes.update.url(props.equipment.id));
}

const qrCodeUrl = equipmentRoutes.qrCode.url(props.equipment.id);
</script>

<template>
    <Head :title="`Edit ${displayPropertyNo}`" />

    <div class="flex flex-col gap-10 p-4">
        <div class="flex flex-col gap-6">
            <Heading
                :title="`Edit ${displayPropertyNo}`"
                :description="`${props.equipment.item} — currently held by ${props.equipment.current_accountable_officer?.full_name ?? 'no one on record'}`"
            />

            <Card>
                <CardHeader>
                    <CardTitle class="text-base">QR code</CardTitle>
                </CardHeader>
                <CardContent class="flex flex-col items-start gap-4 sm:flex-row sm:items-center">
                    <img
                        :src="qrCodeUrl"
                        :alt="`QR code for property no. ${displayPropertyNo}`"
                        class="size-32 rounded-md border bg-white p-2"
                        width="128"
                        height="128"
                    >
                    <div class="flex flex-col gap-2">
                        <p class="text-sm text-muted-foreground">
                            Scan or print this code to identify property no.
                            {{ displayPropertyNo }}.
                        </p>
                        <div class="flex gap-2">
                            <a :href="qrCodeUrl" target="_blank" rel="noopener noreferrer">
                                <Button type="button" variant="outline" size="sm">
                                    <Printer class="size-4" />
                                    Print / open
                                </Button>
                            </a>
                            <a :href="qrCodeUrl" download>
                                <Button type="button" variant="outline" size="sm">
                                    <Download class="size-4" />
                                    Download
                                </Button>
                            </a>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <form class="space-y-8" @submit.prevent="submit">
                <EquipmentForm :form="form" :options="props.options" />

                <div class="flex items-center gap-4">
                    <Button type="submit" :disabled="form.processing" data-test="update-equipment-button">
                        <Spinner v-if="form.processing" />
                        Save changes
                    </Button>
                    <Link :href="equipmentRoutes.index()">
                        <Button type="button" variant="secondary">Cancel</Button>
                    </Link>
                </div>
            </form>
        </div>

        <div class="border-t pt-8">
            <TransactionLog
                :equipment-id="props.equipment.id"
                :transactions="props.equipment.transactions"
                :transaction-options="props.transactionOptions"
                :personnel-options="props.personnelOptions"
            />
        </div>
    </div>
</template>
