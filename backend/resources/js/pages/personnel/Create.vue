<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import PersonnelForm from '@/pages/personnel/Partials/Form.vue';
import personnel from '@/routes/personnel';
import type { PersonnelFormData, PersonnelFormOptions } from '@/types/personnel';

const props = defineProps<{
    options: PersonnelFormOptions;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Personnel', href: personnel.index() },
            { title: 'Add personnel', href: personnel.create() },
        ],
    },
});

const form = useForm<PersonnelFormData>({
    employee_id: '',
    last_name: '',
    first_name: '',
    middle_name: '',
    suffix: '',
    ro_office_id: null,
    sdo_office_id: null,
    position_id: null,
    oic: false,
    oic_office: '',
    mobile_1: '',
    mobile_2: '',
    deped_email: '',
    personal_email: '',
    date_hired: '',
    non_deped_funded: false,
    fund_source: [],
    inactive: false,
    separation_date: '',
    separation_cause: '',
    transferred_from: '',
    transferred_to: '',
});

function submit() {
    form.post(personnel.store.url());
}
</script>

<template>
    <Head title="Add personnel" />

    <div class="flex flex-col gap-6 p-4">
        <Heading
            title="Add personnel"
            description="Create a new personnel record."
        />

        <form class="space-y-8" @submit.prevent="submit">
            <PersonnelForm :form="form" :options="props.options" />

            <div class="flex items-center gap-4">
                <Button type="submit" :disabled="form.processing" data-test="save-personnel-button">
                    <Spinner v-if="form.processing" />
                    Save personnel
                </Button>
                <Link :href="personnel.index()">
                    <Button type="button" variant="secondary">Cancel</Button>
                </Link>
            </div>
        </form>
    </div>
</template>
