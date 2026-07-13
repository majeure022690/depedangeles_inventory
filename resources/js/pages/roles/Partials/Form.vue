<script setup lang="ts">
import type { InertiaForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import CheckboxGroupField from '@/components/CheckboxGroupField.vue';
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import type { PermissionOption, RoleFormData } from '@/types/roles';

const props = withDefaults(
    defineProps<{
        form: InertiaForm<RoleFormData>;
        permissions: PermissionOption[];
        isProtected?: boolean;
    }>(),
    {
        isProtected: false,
    },
);

/**
 * Human-friendly headings for the permission groups below, keyed by the
 * value string's prefix (the segment before its first '.'). Only covers
 * the resource prefixes App\Enums\Permission defines today — an unmapped
 * prefix still renders (title-cased fallback below) rather than being
 * dropped, so a future permission added without updating this map degrades
 * gracefully instead of silently disappearing.
 */
const GROUP_LABELS: Record<string, string> = {
    personnel: 'Personnel',
    equipment: 'Equipment',
    isp_accounts: 'ISP Accounts',
    'reference-data': 'Reference Data',
    users: 'Users',
    roles: 'Roles',
    stakeholder_profile: 'Stakeholder Profile',
    internet_connectivity: 'Internet Connectivity',
};

function fallbackLabel(prefix: string): string {
    return prefix
        .replace(/[_-]/g, ' ')
        .replace(/\b\w/g, (char) => char.toUpperCase());
}

/**
 * Groups the flat permission catalog by resource prefix so the checkbox
 * list reads as ~9 short sections instead of one 20+-item wall — each
 * group is still just a CheckboxGroupField bound to the same `permissions`
 * array field, so toggling in any group mutates the one shared list.
 */
const permissionGroups = computed(() => {
    const groups = new Map<string, PermissionOption[]>();

    for (const permission of props.permissions) {
        const prefix = permission.value.split('.')[0];
        const bucket = groups.get(prefix);

        if (bucket) {
            bucket.push(permission);
        } else {
            groups.set(prefix, [permission]);
        }
    }

    return Array.from(groups.entries()).map(([prefix, options]) => ({
        prefix,
        label: GROUP_LABELS[prefix] ?? fallbackLabel(prefix),
        options,
    }));
});
</script>

<template>
    <div class="space-y-8">
        <fieldset class="space-y-4">
            <legend class="text-base font-medium">Details</legend>
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="name">Name (key)</Label>
                    <!-- eslint-disable vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Input
                        id="name"
                        v-model="form.name"
                        required
                        autocomplete="off"
                        :disabled="isProtected"
                    />
                    <!-- eslint-enable vue/no-mutating-props -->
                    <p v-if="isProtected" class="text-xs text-muted-foreground">
                        This role's name is fixed and cannot be changed.
                    </p>
                    <p v-else class="text-xs text-muted-foreground">
                        Lowercase letters, numbers, dashes, and underscores only.
                    </p>
                    <InputError :message="form.errors.name" />
                </div>
                <div class="grid gap-2">
                    <Label for="label">Display label</Label>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Input id="label" v-model="form.label" required />
                    <InputError :message="form.errors.label" />
                </div>
                <div class="grid gap-2 sm:col-span-2">
                    <Label for="description">Description</Label>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Textarea id="description" v-model="form.description" rows="2" />
                    <InputError :message="form.errors.description" />
                </div>
            </div>
        </fieldset>

        <fieldset class="space-y-4">
            <legend class="text-base font-medium">Permissions</legend>
            <p v-if="isProtected" class="text-sm text-muted-foreground">
                This role must always have zero permissions — it is the deny-by-default holding
                pen for new self-registrations. Permissions cannot be granted here.
            </p>
            <div class="grid gap-4 sm:grid-cols-2">
                <fieldset
                    v-for="group in permissionGroups"
                    :key="group.prefix"
                    class="space-y-2 rounded-lg border border-input bg-muted/30 p-4"
                >
                    <legend class="px-1 text-sm font-medium">{{ group.label }}</legend>
                    <CheckboxGroupField
                        :form="form"
                        field="permissions"
                        :options="group.options"
                        :id-prefix="`permission-${group.prefix}`"
                        :disabled="isProtected"
                    />
                </fieldset>
            </div>
            <InputError :message="form.errors.permissions" />
        </fieldset>
    </div>
</template>
