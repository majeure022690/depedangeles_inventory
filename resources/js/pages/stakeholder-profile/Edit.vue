<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { usePermissions } from '@/composables/usePermissions';
import StakeholderProfileForm from '@/pages/stakeholder-profile/Partials/Form.vue';
import * as stakeholderProfileRoutes from '@/routes/stakeholder-profile';
import type { StakeholderProfileFormData, StakeholderProfileFormOptions, StakeholderProfileFull } from '@/types/stakeholder-profile';

const props = defineProps<{
    stakeholderProfile: StakeholderProfileFull;
    options: StakeholderProfileFormOptions;
}>();

const { can } = usePermissions();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Stakeholder Profile', href: stakeholderProfileRoutes.edit() }],
    },
});

/**
 * ISO 8601 (`toIso8601String()`, e.g. "2026-07-10T09:00:00+08:00") ->
 * `datetime-local` input value ("2026-07-10T09:00"). One-off conversion,
 * only needed here, so it isn't extracted to a shared util.
 */
function toDatetimeLocal(value: string | null): string {
    return value ? value.slice(0, 16) : '';
}

const form = useForm<StakeholderProfileFormData>({
    governance_level: props.stakeholderProfile.governance_level ?? '',
    ro: props.stakeholderProfile.ro ?? '',
    sdo: props.stakeholderProfile.sdo ?? '',

    school_district: props.stakeholderProfile.school_district ?? '',
    school_name: props.stakeholderProfile.school_name ?? '',
    school_id: props.stakeholderProfile.school_id ?? '',

    province: props.stakeholderProfile.province ?? '',
    city_municipality: props.stakeholderProfile.city_municipality ?? '',
    legislative_district: props.stakeholderProfile.legislative_district ?? '',
    barangay: props.stakeholderProfile.barangay ?? '',
    street: props.stakeholderProfile.street ?? '',
    psgc: props.stakeholderProfile.psgc ?? '',

    notes_corrections: props.stakeholderProfile.notes_corrections ?? '',
    notes_recent_development: props.stakeholderProfile.notes_recent_development ?? '',

    mobile_1: props.stakeholderProfile.mobile_1 ?? '',
    mobile_2: props.stakeholderProfile.mobile_2 ?? '',
    landline: props.stakeholderProfile.landline ?? '',

    chief_name: props.stakeholderProfile.chief_name ?? '',
    chief_position: props.stakeholderProfile.chief_position ?? '',
    chief_email: props.stakeholderProfile.chief_email ?? '',
    chief_mobile: props.stakeholderProfile.chief_mobile ?? '',

    admin_staff_name: props.stakeholderProfile.admin_staff_name ?? '',
    admin_staff_position: props.stakeholderProfile.admin_staff_position ?? '',
    admin_staff_email: props.stakeholderProfile.admin_staff_email ?? '',
    admin_staff_mobile: props.stakeholderProfile.admin_staff_mobile ?? '',

    network_administrator_name: props.stakeholderProfile.network_administrator_name ?? '',

    longitude: props.stakeholderProfile.longitude !== null ? String(props.stakeholderProfile.longitude) : '',
    latitude: props.stakeholderProfile.latitude !== null ? String(props.stakeholderProfile.latitude) : '',

    nearby_institutions: props.stakeholderProfile.nearby_institutions ?? [],
    nearby_institutions_other: props.stakeholderProfile.nearby_institutions_other ?? '',

    travel_time_to_center_minutes:
        props.stakeholderProfile.travel_time_to_center_minutes !== null
            ? String(props.stakeholderProfile.travel_time_to_center_minutes)
            : '',

    access_paths: props.stakeholderProfile.access_paths ?? [],

    transportation_options: props.stakeholderProfile.transportation_options ?? [],
    transportation_other: props.stakeholderProfile.transportation_other ?? '',

    transportation_difficult: props.stakeholderProfile.transportation_difficult ?? false,
    considered_very_remote: props.stakeholderProfile.considered_very_remote ?? false,
    remote_context_notes: props.stakeholderProfile.remote_context_notes ?? '',

    gidca: props.stakeholderProfile.gidca ?? false,
    lms: props.stakeholderProfile.lms ?? false,

    community_engagement: props.stakeholderProfile.community_engagement ?? [],
    community_context_remarks: props.stakeholderProfile.community_context_remarks ?? '',

    submitted_at: toDatetimeLocal(props.stakeholderProfile.submitted_at),
    transaction_type: props.stakeholderProfile.transaction_type ?? '',
});

function submit() {
    if (!can('stakeholder_profile.edit')) {
        toast.error('You do not have permission to edit the Stakeholder Profile.');

        return;
    }

    form.put(stakeholderProfileRoutes.update.url(), {
        onError: () => {
            toast.error('Unable to save changes. Please review the highlighted fields and try again.');
        },
    });
}
</script>

<template>
    <Head title="Stakeholder Profile" />

    <div class="flex flex-col gap-6 p-4">
        <Heading
            title="Stakeholder Profile"
            description="Division Office identity, location, contacts, and community context."
        />

        <form class="space-y-8" @submit.prevent="submit">
            <StakeholderProfileForm
                :form="form"
                :options="props.options"
                :complete-address="props.stakeholderProfile.complete_address"
                :disabled="!can('stakeholder_profile.edit')"
            />

            <div v-if="can('stakeholder_profile.edit')" class="flex items-center gap-4">
                <Button type="submit" :disabled="form.processing" data-test="update-stakeholder-profile-button">
                    <Spinner v-if="form.processing" />
                    Save changes
                </Button>
            </div>
        </form>
    </div>
</template>
