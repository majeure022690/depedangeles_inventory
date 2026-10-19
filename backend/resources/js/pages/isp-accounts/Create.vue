<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import IspAccountForm from '@/pages/isp-accounts/Partials/Form.vue';
import ispAccounts from '@/routes/isp-accounts';
import type { IspAccountFormData, IspAccountFormOptions } from '@/types/isp-account';

const props = defineProps<{
    options: IspAccountFormOptions;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'ISP Accounts', href: ispAccounts.index() },
            { title: 'Add ISP account', href: ispAccounts.create() },
        ],
    },
});

// isp_provider_id (Tier 1 reference-table field) defaults to 0 (not a
// valid row id — the same "unset" sentinel role the empty string used to
// play), so the <Select> shows its placeholder until the user picks a real
// option — matching equipment/Create.vue's convention for the same field
// shape.
const form = useForm<IspAccountFormData>({
    isp_provider_id: 0,
    cost_per_month: '',
    isp_billing_account_no: '',
    package_purchased_inclusion: '',
    school_area_coverage: '',
    min_speed: '',
    max_speed: '',
    subscription_type: '',
    isp_connection_type: '',
    contract_start_date: '',
    contract_end_date: '',
    inactive_contract: false,
    purpose_of_subscription: '',
    mode_of_acquisition: '',
    source_of_acquisition: '',
    donor: '',
    fund_source: '',
    number_access_points_linked: '',
    locations_access_points: '',
    number_admin_area_covered: '',
    rate_connectivity_admin_areas: '',
    number_classrooms_covered: '',
    rate_connectivity_classroom: '',
    overall_signal_quality: '',
    rate_isp_service: '',
});

function submit() {
    form.post(ispAccounts.store.url());
}
</script>

<template>
    <Head title="Add ISP account" />

    <div class="flex flex-col gap-6 p-4">
        <Heading
            title="Add ISP account"
            description="Register a new internet service subscription."
        />

        <form class="space-y-8" @submit.prevent="submit">
            <IspAccountForm :form="form" :options="props.options" />

            <div class="flex items-center gap-4">
                <Button type="submit" :disabled="form.processing" data-test="save-isp-account-button">
                    <Spinner v-if="form.processing" />
                    Save ISP account
                </Button>
                <Link :href="ispAccounts.index()">
                    <Button type="button" variant="secondary">Cancel</Button>
                </Link>
            </div>
        </form>
    </div>
</template>
