<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import IspAccountForm from '@/pages/isp-accounts/Partials/Form.vue';
import SpeedTestLog from '@/pages/isp-accounts/Partials/SpeedTestLog.vue';
import SubscriptionCostLog from '@/pages/isp-accounts/Partials/SubscriptionCostLog.vue';
import * as ispAccountsRoutes from '@/routes/isp-accounts';
import type {
    IspAccountFormData,
    IspAccountFormOptions,
    IspAccountFull,
    IspSpeedTestFormOptions,
} from '@/types/isp-account';

const props = defineProps<{
    ispAccount: IspAccountFull;
    options: IspAccountFormOptions;
    speedTestOptions: IspSpeedTestFormOptions;
}>();

// Note: defineOptions() is hoisted to module scope at compile time, so it
// cannot reference `props` (route params like the ISP account id are only
// known at runtime) — the trailing crumb below intentionally links back to
// the index rather than to this exact record.
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'ISP Accounts', href: ispAccountsRoutes.index() },
            { title: 'Edit ISP account', href: ispAccountsRoutes.index() },
        ],
    },
});

const form = useForm<IspAccountFormData>({
    isp_provider_id: props.ispAccount.isp_provider_id,
    cost_per_month: String(props.ispAccount.cost_per_month),
    isp_billing_account_no: props.ispAccount.isp_billing_account_no,
    package_purchased_inclusion: props.ispAccount.package_purchased_inclusion,
    school_area_coverage: props.ispAccount.school_area_coverage,
    min_speed: String(props.ispAccount.min_speed),
    max_speed: String(props.ispAccount.max_speed),
    subscription_type: props.ispAccount.subscription_type,
    isp_connection_type: props.ispAccount.isp_connection_type,
    contract_start_date: props.ispAccount.contract_start_date ?? '',
    contract_end_date: props.ispAccount.contract_end_date ?? '',
    inactive_contract: props.ispAccount.inactive_contract,
    purpose_of_subscription: props.ispAccount.purpose_of_subscription,
    mode_of_acquisition: props.ispAccount.mode_of_acquisition,
    source_of_acquisition: props.ispAccount.source_of_acquisition,
    donor: props.ispAccount.donor ?? '',
    fund_source: props.ispAccount.fund_source,
    number_access_points_linked: props.ispAccount.number_access_points_linked
        ? String(props.ispAccount.number_access_points_linked)
        : '',
    locations_access_points: props.ispAccount.locations_access_points ?? '',
    number_admin_area_covered: props.ispAccount.number_admin_area_covered
        ? String(props.ispAccount.number_admin_area_covered)
        : '',
    rate_connectivity_admin_areas: props.ispAccount.rate_connectivity_admin_areas ?? '',
    number_classrooms_covered: props.ispAccount.number_classrooms_covered
        ? String(props.ispAccount.number_classrooms_covered)
        : '',
    rate_connectivity_classroom: props.ispAccount.rate_connectivity_classroom ?? '',
    overall_signal_quality: props.ispAccount.overall_signal_quality,
    rate_isp_service: props.ispAccount.rate_isp_service ? String(props.ispAccount.rate_isp_service) : '',
});

function submit() {
    form.put(ispAccountsRoutes.update.url(props.ispAccount.id));
}
</script>

<template>
    <Head :title="`Edit ${props.ispAccount.isp}`" />

    <div class="flex flex-col gap-10 p-4">
        <div class="flex flex-col gap-6">
            <Heading
                :title="`Edit ${props.ispAccount.isp}`"
                :description="`Billing account no. ${props.ispAccount.isp_billing_account_no}`"
            />

            <form class="space-y-8" @submit.prevent="submit">
                <IspAccountForm :form="form" :options="props.options" />

                <div class="flex items-center gap-4">
                    <Button type="submit" :disabled="form.processing" data-test="update-isp-account-button">
                        <Spinner v-if="form.processing" />
                        Save changes
                    </Button>
                    <Link :href="ispAccountsRoutes.index()">
                        <Button type="button" variant="secondary">Cancel</Button>
                    </Link>
                </div>
            </form>
        </div>

        <div class="border-t pt-8">
            <SpeedTestLog
                :isp-account-id="props.ispAccount.id"
                :speed-tests="props.ispAccount.speed_tests"
                :speed-test-options="props.speedTestOptions"
            />
        </div>

        <div class="border-t pt-8">
            <SubscriptionCostLog
                :isp-account-id="props.ispAccount.id"
                :subscription-costs="props.ispAccount.subscription_costs"
            />
        </div>
    </div>
</template>
