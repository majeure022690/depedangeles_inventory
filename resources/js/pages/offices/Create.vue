<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import OfficeForm from '@/pages/offices/Partials/Form.vue';
import offices from '@/routes/offices';
import type { OfficeFormData } from '@/types/office';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Offices', href: offices.index() },
            { title: 'Add office', href: offices.create() },
        ],
    },
});

const form = useForm<OfficeFormData>({
    office_name: '',
    office_type: '',
    school_id: '',
    address: '',
    region: '',
    district: '',
    division: '',
    contact_number: '',
    email: '',
    is_active: true,
});

function submit() {
    form.post(offices.store.url());
}
</script>

<template>
    <Head title="Add office" />

    <div class="flex flex-col gap-6 p-4">
        <Heading
            title="Add office"
            description="Register a new school or division-level office/unit."
        />

        <form class="space-y-8" @submit.prevent="submit">
            <OfficeForm :form="form" />

            <div class="flex items-center gap-4">
                <Button type="submit" :disabled="form.processing" data-test="save-office-button">
                    <Spinner v-if="form.processing" />
                    Save office
                </Button>
                <Link :href="offices.index()">
                    <Button type="button" variant="secondary">Cancel</Button>
                </Link>
            </div>
        </form>
    </div>
</template>
