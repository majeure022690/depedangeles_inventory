<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import OfficeForm from '@/pages/offices/Partials/Form.vue';
import * as officesRoutes from '@/routes/offices';
import type { OfficeFormData, OfficeFull } from '@/types/office';

const props = defineProps<{
    office: OfficeFull;
}>();

// Note: defineOptions() is hoisted to module scope at compile time, so it
// cannot reference `props` (route params like the office id are only known
// at runtime) — the trailing crumb below intentionally links back to the
// index rather than to this exact record, matching equipment/Edit.vue's
// convention for the same constraint.
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Offices', href: officesRoutes.index() },
            { title: 'Edit office', href: officesRoutes.index() },
        ],
    },
});

const form = useForm<OfficeFormData>({
    office_name: props.office.office_name,
    office_type: props.office.office_type ?? '',
    school_id: props.office.school_id !== null ? String(props.office.school_id) : '',
    address: props.office.address ?? '',
    region: props.office.region ?? '',
    district: props.office.district ?? '',
    division: props.office.division ?? '',
    contact_number: props.office.contact_number ?? '',
    email: props.office.email ?? '',
    is_active: props.office.is_active,
});

function submit() {
    form.put(officesRoutes.update.url(props.office.id));
}
</script>

<template>
    <Head :title="`Edit ${props.office.office_name}`" />

    <div class="flex flex-col gap-6 p-4">
        <Heading
            :title="`Edit ${props.office.office_name}`"
            description="Update this office's identification, location, and contact details."
        />

        <form class="space-y-8" @submit.prevent="submit">
            <OfficeForm :form="form" />

            <div class="flex items-center gap-4">
                <Button type="submit" :disabled="form.processing" data-test="update-office-button">
                    <Spinner v-if="form.processing" />
                    Save changes
                </Button>
                <Link :href="officesRoutes.index()">
                    <Button type="button" variant="secondary">Cancel</Button>
                </Link>
            </div>
        </form>
    </div>
</template>
