<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import PersonnelForm from '@/pages/personnel/Partials/Form.vue';
import * as personnelRoutes from '@/routes/personnel';
import type { PersonnelFormData, PersonnelFormOptions, PersonnelFull } from '@/types/personnel';

const props = defineProps<{
    personnel: PersonnelFull;
    options: PersonnelFormOptions;
}>();

// Note: defineOptions() is hoisted to module scope at compile time, so it
// cannot reference `props` (route params like the personnel id are only
// known at runtime) — the trailing crumb below intentionally links back to
// the index rather than to this exact record.
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Personnel', href: personnelRoutes.index() },
            { title: 'Edit personnel', href: personnelRoutes.index() },
        ],
    },
});

const form = useForm<PersonnelFormData>({
    employee_id: props.personnel.employee_id,
    last_name: props.personnel.last_name,
    first_name: props.personnel.first_name,
    middle_name: props.personnel.middle_name ?? '',
    suffix: props.personnel.suffix ?? '',
    ro_office_id: props.personnel.ro_office_id ?? null,
    sdo_office_id: props.personnel.sdo_office_id ?? null,
    position_id: props.personnel.position_id ?? null,
    oic: props.personnel.oic,
    oic_office: props.personnel.oic_office ?? '',
    mobile_1: props.personnel.mobile_1 ?? '',
    mobile_2: props.personnel.mobile_2 ?? '',
    deped_email: props.personnel.deped_email ?? '',
    personal_email: props.personnel.personal_email ?? '',
    date_hired: props.personnel.date_hired ?? '',
    non_deped_funded: props.personnel.non_deped_funded,
    fund_source: props.personnel.fund_source ?? [],
    inactive: props.personnel.inactive,
    separation_date: props.personnel.separation_date ?? '',
    separation_cause: props.personnel.separation_cause ?? '',
    transferred_from: props.personnel.transferred_from ?? '',
    transferred_to: props.personnel.transferred_to ?? '',
});

function submit() {
    form.put(personnelRoutes.update.url(props.personnel.id));
}
</script>

<template>
    <Head :title="`Edit ${props.personnel.full_name}`" />

    <div class="flex flex-col gap-6 p-4">
        <Heading
            :title="`Edit ${props.personnel.full_name}`"
            description="Update this personnel record."
        />

        <form class="space-y-8" @submit.prevent="submit">
            <PersonnelForm :form="form" :options="props.options" />

            <div class="flex items-center gap-4">
                <Button type="submit" :disabled="form.processing" data-test="update-personnel-button">
                    <Spinner v-if="form.processing" />
                    Save changes
                </Button>
                <Link :href="personnelRoutes.index()">
                    <Button type="button" variant="secondary">Cancel</Button>
                </Link>
            </div>
        </form>
    </div>
</template>
