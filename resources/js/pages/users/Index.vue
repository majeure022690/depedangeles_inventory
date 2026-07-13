<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import CheckboxGroupField from '@/components/CheckboxGroupField.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import Pagination from '@/components/Pagination.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useTableFilters } from '@/composables/useTableFilters';
import * as usersRoutes from '@/routes/users';
import type { Paginated } from '@/types/pagination';
import type { RoleOption, UserFilters, UserListItem } from '@/types/users';

const props = defineProps<{
    users: Paginated<UserListItem>;
    roles: RoleOption[];
    filters: UserFilters;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Users', href: usersRoutes.index() }],
    },
});

const ALL = '__all__';

const { filters, update } = useTableFilters(usersRoutes.index.url(), {
    search: props.filters.search ?? '',
    role: props.filters.role ?? ALL,
}, {
    debounceKeys: ['search'],
});

function updateRoleFilter(value: string) {
    update('role', value === ALL ? '' : value);
}

const roleOptions = computed(() => props.roles.map((role) => ({ value: role.id, label: role.label })));

const editingUser = ref<UserListItem | null>(null);

const form = useForm<{ role_ids: number[] }>({
    role_ids: [],
});

function openEditRoles(row: UserListItem) {
    if (row.is_self) {
        return;
    }

    editingUser.value = row;
    form.clearErrors();
    form.defaults({ role_ids: [...row.role_ids] });
    form.reset();
}

function closeDialog() {
    editingUser.value = null;
    form.clearErrors();
}

function submit() {
    if (!editingUser.value) {
        return;
    }

    form.patch(usersRoutes.update.url(editingUser.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            editingUser.value = null;
        },
    });
}
</script>

<template>
    <Head title="Users" />

    <div class="flex flex-col gap-6 p-4">
        <Heading
            title="User Management"
            description="Assign roles to control what each user can see and do."
        />

        <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
            <div class="sm:max-w-sm sm:flex-1">
                <Input
                    :model-value="filters.search"
                    type="search"
                    placeholder="Search by name or email..."
                    aria-label="Search users"
                    @update:model-value="(value) => update('search', String(value))"
                />
            </div>
            <Select
                :model-value="filters.role"
                @update:model-value="(value) => updateRoleFilter(String(value))"
            >
                <SelectTrigger class="w-full sm:w-48" aria-label="Filter by role">
                    <SelectValue placeholder="Role" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem :value="ALL">All roles</SelectItem>
                    <SelectItem v-for="role in roles" :key="role.id" :value="role.name">
                        {{ role.label }}
                    </SelectItem>
                </SelectContent>
            </Select>
        </div>

        <div class="overflow-x-auto rounded-lg border">
            <table class="w-full text-left text-sm">
                <thead class="border-b bg-muted/50 text-xs uppercase text-muted-foreground">
                    <tr>
                        <th scope="col" class="px-4 py-3 font-medium">Name</th>
                        <th scope="col" class="px-4 py-3 font-medium">Email</th>
                        <th scope="col" class="px-4 py-3 font-medium">Roles</th>
                        <th scope="col" class="px-4 py-3 font-medium">
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-if="props.users.data.length === 0">
                        <td colspan="4" class="px-4 py-8 text-center text-muted-foreground">
                            No users found.
                        </td>
                    </tr>
                    <tr v-for="row in props.users.data" :key="row.id">
                        <td class="px-4 py-3">
                            <div class="font-medium">
                                {{ row.name }}
                                <span v-if="row.is_self" class="text-xs text-muted-foreground">(you)</span>
                            </div>
                        </td>
                        <td class="px-4 py-3">{{ row.email }}</td>
                        <td class="px-4 py-3">
                            <div class="flex flex-wrap gap-1">
                                <Badge
                                    v-for="role in row.roles"
                                    :key="role.id"
                                    variant="secondary"
                                >
                                    {{ role.label }}
                                </Badge>
                                <span v-if="row.roles.length === 0" class="text-muted-foreground">—</span>
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center justify-end">
                                <TooltipProvider v-if="row.is_self">
                                    <Tooltip>
                                        <TooltipTrigger as-child>
                                            <span tabindex="0" class="inline-block">
                                                <Button variant="outline" size="sm" disabled>
                                                    Edit roles
                                                </Button>
                                            </span>
                                        </TooltipTrigger>
                                        <TooltipContent>
                                            You cannot change your own roles.
                                        </TooltipContent>
                                    </Tooltip>
                                </TooltipProvider>
                                <Button
                                    v-else
                                    variant="outline"
                                    size="sm"
                                    :aria-label="`Edit roles for ${row.name}`"
                                    @click="openEditRoles(row)"
                                >
                                    Edit roles
                                </Button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination :links="props.users.links" />
    </div>

    <Dialog :open="editingUser !== null" @update:open="(open) => !open && closeDialog()">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Edit roles</DialogTitle>
                <DialogDescription>
                    Choose the roles <strong>{{ editingUser?.name }}</strong> should hold. A user
                    may hold zero, one, or several roles at once — their effective permissions are
                    the union of every role granted.
                </DialogDescription>
            </DialogHeader>

            <form v-if="editingUser" class="space-y-4" @submit.prevent="submit">
                <CheckboxGroupField
                    :form="form"
                    field="role_ids"
                    :options="roleOptions"
                    id-prefix="user-role"
                />
                <InputError :message="form.errors.role_ids" />

                <DialogFooter class="gap-2">
                    <Button type="button" variant="secondary" @click="closeDialog">Cancel</Button>
                    <Button type="submit" :disabled="form.processing" data-test="save-user-roles-button">
                        <Spinner v-if="form.processing" />
                        Save changes
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
