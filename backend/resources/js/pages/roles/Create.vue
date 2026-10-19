<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import RoleForm from '@/pages/roles/Partials/Form.vue';
import roles from '@/routes/roles';
import type { PermissionOption, RoleFormData } from '@/types/roles';

const props = defineProps<{
    permissions: PermissionOption[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Roles', href: roles.index() },
            { title: 'Create role', href: roles.create() },
        ],
    },
});

const form = useForm<RoleFormData>({
    name: '',
    label: '',
    description: '',
    permissions: [],
});

function submit() {
    form.post(roles.store.url());
}
</script>

<template>
    <Head title="Create role" />

    <div class="flex flex-col gap-6 p-4">
        <Heading
            title="Create role"
            description="Define a new role and the permissions it grants."
        />

        <form class="space-y-8" @submit.prevent="submit">
            <RoleForm :form="form" :permissions="props.permissions" />

            <div class="flex items-center gap-4">
                <Button type="submit" :disabled="form.processing" data-test="save-role-button">
                    <Spinner v-if="form.processing" />
                    Save role
                </Button>
                <Link :href="roles.index()">
                    <Button type="button" variant="secondary">Cancel</Button>
                </Link>
            </div>
        </form>
    </div>
</template>
