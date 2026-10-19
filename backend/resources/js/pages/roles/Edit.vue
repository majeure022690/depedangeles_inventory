<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import RoleForm from '@/pages/roles/Partials/Form.vue';
import * as rolesRoutes from '@/routes/roles';
import type { PermissionOption, RoleFormData, RoleFormRole } from '@/types/roles';

const props = defineProps<{
    role: RoleFormRole;
    permissions: PermissionOption[];
}>();

// Note: defineOptions() is hoisted to module scope at compile time, so it
// cannot reference `props` (the role's label is only known at runtime) —
// the trailing crumb below intentionally links back to the index rather
// than to this exact record.
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Roles', href: rolesRoutes.index() },
            { title: 'Edit role', href: rolesRoutes.index() },
        ],
    },
});

const form = useForm<RoleFormData>({
    name: props.role.name,
    label: props.role.label,
    description: props.role.description ?? '',
    permissions: [...props.role.permissions],
});

function submit() {
    form.patch(rolesRoutes.update.url(props.role.id));
}
</script>

<template>
    <Head :title="`Edit ${props.role.label}`" />

    <div class="flex flex-col gap-6 p-4">
        <Heading
            :title="`Edit ${props.role.label}`"
            description="Update the role's details and the permissions it grants."
        />

        <form class="space-y-8" @submit.prevent="submit">
            <RoleForm
                :form="form"
                :permissions="props.permissions"
                :is-protected="props.role.is_protected"
            />

            <div class="flex items-center gap-4">
                <Button type="submit" :disabled="form.processing" data-test="save-role-button">
                    <Spinner v-if="form.processing" />
                    Save changes
                </Button>
                <Link :href="rolesRoutes.index()">
                    <Button type="button" variant="secondary">Cancel</Button>
                </Link>
            </div>
        </form>
    </div>
</template>
