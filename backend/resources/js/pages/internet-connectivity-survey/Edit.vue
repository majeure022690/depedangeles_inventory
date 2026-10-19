<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { usePermissions } from '@/composables/usePermissions';
import InternetConnectivitySurveyForm from '@/pages/internet-connectivity-survey/Partials/Form.vue';
import * as internetConnectivitySurveyRoutes from '@/routes/internet-connectivity-surveys';
import type {
    InternetConnectivitySurveyFormData,
    InternetConnectivitySurveyFormOptions,
    InternetConnectivitySurveyFull,
} from '@/types/internet-connectivity-survey';

const props = defineProps<{
    office: { id: number; office_name: string };
    internetConnectivitySurvey: InternetConnectivitySurveyFull;
    options: InternetConnectivitySurveyFormOptions;
}>();

const { can } = usePermissions();

// defineOptions() can't reference props, so both crumbs link to the index.
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Internet Connectivity Survey', href: internetConnectivitySurveyRoutes.index() },
            { title: 'Edit survey', href: internetConnectivitySurveyRoutes.index() },
        ],
    },
});

const form = useForm<InternetConnectivitySurveyFormData>({
    has_isp_in_area: props.internetConnectivitySurvey.has_isp_in_area ?? false,

    available_isps: props.internetConnectivitySurvey.available_isps ?? [],
    available_isps_other: props.internetConnectivitySurvey.available_isps_other ?? '',

    mobile_signal_types: props.internetConnectivitySurvey.mobile_signal_types ?? [],

    has_mobile_data_connectivity: props.internetConnectivitySurvey.has_mobile_data_connectivity ?? false,
    mobile_data_quality: props.internetConnectivitySurvey.mobile_data_quality ?? '',

    subscribes_to_isp: props.internetConnectivitySurvey.subscribes_to_isp ?? false,
    subscribed_isps: props.internetConnectivitySurvey.subscribed_isps ?? [],

    insufficient_bandwidth_explanation: props.internetConnectivitySurvey.insufficient_bandwidth_explanation ?? '',

    coverage_areas: props.internetConnectivitySurvey.coverage_areas ?? [],
    coverage_areas_other: props.internetConnectivitySurvey.coverage_areas_other ?? '',

    dict_free_wifi_recipient: props.internetConnectivitySurvey.dict_free_wifi_recipient ?? false,
    dict_free_wifi_rating:
        props.internetConnectivitySurvey.dict_free_wifi_rating !== null
            ? String(props.internetConnectivitySurvey.dict_free_wifi_rating)
            : '',

    has_sufficient_bandwidth: props.internetConnectivitySurvey.has_sufficient_bandwidth ?? false,
    no_subscription_reason: props.internetConnectivitySurvey.no_subscription_reason ?? '',

    has_electricity_source: props.internetConnectivitySurvey.has_electricity_source ?? false,
    electricity_sources: props.internetConnectivitySurvey.electricity_sources ?? [],
    primarily_solar_powered: props.internetConnectivitySurvey.primarily_solar_powered ?? false,
    frequent_brownouts: props.internetConnectivitySurvey.frequent_brownouts ?? false,

    rooms_other_use:
        props.internetConnectivitySurvey.rooms_other_use !== null ? String(props.internetConnectivitySurvey.rooms_other_use) : '',
});

function submit() {
    if (!can('internet_connectivity.edit')) {
        toast.error('You do not have permission to edit the Internet Connectivity Survey.');

        return;
    }

    form.put(internetConnectivitySurveyRoutes.update.url(props.office.id), {
        onError: () => {
            toast.error('Unable to save changes. Please review the highlighted fields and try again.');
        },
    });
}
</script>

<template>
    <Head title="Internet Connectivity Survey" />

    <div class="flex flex-col gap-6 p-4">
        <Heading
            title="Internet Connectivity Survey"
            description="ISP availability, mobile connectivity, coverage, and electricity for the Division Office."
        />

        <form class="space-y-8" @submit.prevent="submit">
            <InternetConnectivitySurveyForm :form="form" :options="props.options" :disabled="!can('internet_connectivity.edit')" />

            <div v-if="can('internet_connectivity.edit')" class="flex items-center gap-4">
                <Button type="submit" :disabled="form.processing" data-test="update-internet-connectivity-survey-button">
                    <Spinner v-if="form.processing" />
                    Save changes
                </Button>
            </div>
        </form>
    </div>
</template>
