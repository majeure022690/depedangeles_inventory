<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import { toast } from 'vue-sonner';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Progress } from '@/components/ui/progress';
import { Spinner } from '@/components/ui/spinner';
import { usePermissions } from '@/composables/usePermissions';
import StakeholderProfileForm from '@/pages/stakeholder-profile/Partials/Form.vue';
import * as stakeholderProfilesRoutes from '@/routes/stakeholder-profiles';
import type { StakeholderProfileFormData, StakeholderProfileFormOptions, StakeholderProfileFull } from '@/types/stakeholder-profile';

const props = defineProps<{
    office: { id: number; office_name: string };
    stakeholderProfile: StakeholderProfileFull;
    options: StakeholderProfileFormOptions;
}>();

const { can } = usePermissions();

// Note: defineOptions() is hoisted to module scope at compile time, so it
// cannot reference `props` (the office's name/id are only known at
// runtime) — the trailing crumb below intentionally links back to the
// index rather than to this exact office (same pattern as Equipment/
// Personnel's Edit pages).
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Stakeholder Profile', href: stakeholderProfilesRoutes.index() },
            { title: 'Edit profile', href: stakeholderProfilesRoutes.index() },
        ],
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
    if (!can('stakeholder_profile.edit') && !can('stakeholder_profile.view_all')) {
        toast.error('You do not have permission to edit the Stakeholder Profile.');

        return;
    }

    form.put(stakeholderProfilesRoutes.update.url(props.office.id), {
        onError: () => {
            toast.error('Unable to save changes. Please review the highlighted fields and try again.');
        },
    });
}

const canEdit = computed(() => can('stakeholder_profile.edit') || can('stakeholder_profile.view_all'));

/**
 * Fields counted toward completion, grouped by the stepper section (ids
 * match Form.vue's `sections`) — every field in the form except the four
 * boolean flags (false is a legitimate answer, not "not yet filled in")
 * and the two free-text "_other" companion fields (only relevant when
 * their own checkbox group is used, not core progress).
 */
const sectionFields: Record<string, (keyof StakeholderProfileFormData)[]> = {
    location: ['province', 'city_municipality', 'legislative_district', 'barangay', 'street', 'psgc'],
    organization: ['governance_level', 'ro', 'sdo', 'school_district', 'school_name', 'school_id'],
    personnel: [
        'chief_name', 'chief_position', 'chief_email', 'chief_mobile',
        'admin_staff_name', 'admin_staff_position', 'admin_staff_email', 'admin_staff_mobile',
        'network_administrator_name',
    ],
    contacts: ['mobile_1', 'mobile_2', 'landline'],
    coordinates: ['longitude', 'latitude'],
    accessibility: ['nearby_institutions', 'travel_time_to_center_minutes', 'access_paths', 'transportation_options', 'remote_context_notes'],
    community: ['community_engagement', 'community_context_remarks'],
    notes: ['submitted_at', 'transaction_type', 'notes_corrections', 'notes_recent_development'],
};

const completionFields = Object.values(sectionFields).flat();

function isFieldFilled(field: keyof StakeholderProfileFormData): boolean {
    const value = form[field];

    return Array.isArray(value) ? value.length > 0 : String(value).trim() !== '';
}

const completionPercent = computed(() => {
    const filled = completionFields.filter(isFieldFilled).length;

    return Math.round((filled / completionFields.length) * 100);
});

const sectionComplete = computed(() =>
    Object.fromEntries(
        Object.entries(sectionFields).map(([sectionId, fields]) => [sectionId, fields.every(isFieldFilled)]),
    ),
);
</script>

<template>
    <Head :title="`Stakeholder Profile - ${office.office_name}`" />

    <div class="flex flex-col gap-6 p-4">
        <Heading
            :title="`Stakeholder Profile — ${office.office_name}`"
            description="Identity, location, contacts, and community context for this school/office."
        />

        <div class="grid gap-2">
            <div class="flex items-center justify-between text-sm">
                <span class="font-medium">Profile completion</span>
                <span class="text-muted-foreground">{{ completionPercent }}%</span>
            </div>
            <Progress :model-value="completionPercent" />
        </div>

        <form class="space-y-8" @submit.prevent="submit">
            <StakeholderProfileForm
                :form="form"
                :options="props.options"
                :complete-address="props.stakeholderProfile.complete_address"
                :section-complete="sectionComplete"
                :disabled="!canEdit"
            />

            <div v-if="canEdit" class="flex items-center gap-4">
                <Button type="submit" :disabled="form.processing" data-test="update-stakeholder-profile-button">
                    <Spinner v-if="form.processing" />
                    Save changes
                </Button>
            </div>
        </form>
    </div>
</template>
