<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Plus, Trash2 } from '@lucide/vue';
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
import { Label } from '@/components/ui/label';
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
import type { OfficeOption, RoleOption, UserFilters, UserListItem } from '@/types/users';

const props = defineProps<{
    users: Paginated<UserListItem>;
    roles: RoleOption[];
    offices: OfficeOption[];
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

const editForm = useForm<{ name: string; email: string; office_id: number | null; role_ids: number[] }>({
    name: '',
    email: '',
    office_id: null,
    role_ids: [],
});

/** Reka's Select forbids an empty-string/null item value, so a clearable
 * dropdown needs a distinct sentinel translated back to null. */
const NONE = '__none__';

const editOfficeModel = computed<number | typeof NONE>({
    get: () => editForm.office_id ?? NONE,
    set: (value: number | typeof NONE) => {
        editForm.office_id = value === NONE ? null : Number(value);
    },
});

function openEdit(row: UserListItem) {
    if (row.is_self) {
        return;
    }

    editingUser.value = row;
    editForm.clearErrors();
    editForm.defaults({ name: row.name, email: row.email, office_id: row.office?.id ?? null, role_ids: [...row.role_ids] });
    editForm.reset();
}

function closeEditDialog() {
    editingUser.value = null;
    editForm.clearErrors();
}

function submitEdit() {
    if (!editingUser.value) {
        return;
    }

    editForm.patch(usersRoutes.update.url(editingUser.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            editingUser.value = null;
        },
    });
}

const pendingDelete = ref<UserListItem | null>(null);

function requestDelete(row: UserListItem) {
    if (row.is_self) {
        return;
    }

    pendingDelete.value = row;
}

function confirmDelete() {
    if (!pendingDelete.value) {
        return;
    }

    router.delete(usersRoutes.destroy.url(pendingDelete.value.id), {
        preserveScroll: true,
        onFinish: () => {
            pendingDelete.value = null;
        },
    });
}
</script>

<template>
    <Head title="Users" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <Heading
                title="User Management"
                description="Provision accounts and control what each user can see and do."
            />
            <Link :href="usersRoutes.create()">
                <Button data-test="add-user-button">
                    <Plus class="size-4" />
                    Add user
                </Button>
            </Link>
        </div>

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
                        <th scope="col" class="px-4 py-3 font-medium">School / Office</th>
                        <th scope="col" class="px-4 py-3 font-medium">Roles</th>
                        <th scope="col" class="px-4 py-3 font-medium">Created</th>
                        <th scope="col" class="px-4 py-3 font-medium">Modified</th>
                        <th scope="col" class="px-4 py-3 font-medium">
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-if="props.users.data.length === 0">
                        <td colspan="7" class="px-4 py-8 text-center text-muted-foreground">
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
                            <div v-if="row.office">
                                {{ row.office.name }}
                                <div v-if="row.office.school_id" class="text-xs text-muted-foreground">
                                    School ID: {{ row.office.school_id }}
                                </div>
                            </div>
                            <span v-else class="text-muted-foreground">—</span>
                        </td>
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
                        <td class="px-4 py-3 text-xs text-muted-foreground">
                            {{ row.created_at ? new Date(row.created_at).toLocaleString() : '—' }}
                        </td>
                        <td class="px-4 py-3 text-xs text-muted-foreground">
                            {{ row.updated_at ? new Date(row.updated_at).toLocaleString() : '—' }}
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center justify-end gap-2">
                                <TooltipProvider v-if="row.is_self">
                                    <Tooltip>
                                        <TooltipTrigger as-child>
                                            <span tabindex="0" class="inline-block">
                                                <Button variant="outline" size="sm" disabled>
                                                    Edit
                                                </Button>
                                            </span>
                                        </TooltipTrigger>
                                        <TooltipContent>
                                            You cannot edit your own account here.
                                        </TooltipContent>
                                    </Tooltip>
                                </TooltipProvider>
                                <Button
                                    v-else
                                    variant="outline"
                                    size="sm"
                                    :aria-label="`Edit ${row.name}`"
                                    @click="openEdit(row)"
                                >
                                    Edit
                                </Button>
                                <TooltipProvider v-if="row.is_self">
                                    <Tooltip>
                                        <TooltipTrigger as-child>
                                            <span tabindex="0" class="inline-block">
                                                <Button variant="ghost" size="sm" disabled :aria-label="`Delete ${row.name}`">
                                                    <Trash2 class="size-4 text-muted-foreground" />
                                                </Button>
                                            </span>
                                        </TooltipTrigger>
                                        <TooltipContent>
                                            You cannot delete your own account.
                                        </TooltipContent>
                                    </Tooltip>
                                </TooltipProvider>
                                <Button
                                    v-else
                                    variant="ghost"
                                    size="sm"
                                    :aria-label="`Delete ${row.name}`"
                                    @click="requestDelete(row)"
                                >
                                    <Trash2 class="size-4 text-destructive" />
                                </Button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination :links="props.users.links" />
    </div>

    <Dialog :open="editingUser !== null" @update:open="(open) => !open && closeEditDialog()">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Edit user</DialogTitle>
                <DialogDescription>
                    Update <strong>{{ editingUser?.name }}</strong>'s account details and roles. A
                    user may hold zero, one, or several roles at once — their effective
                    permissions are the union of every role granted.
                </DialogDescription>
            </DialogHeader>

            <form v-if="editingUser" class="space-y-4" @submit.prevent="submitEdit">
                <div class="grid gap-2">
                    <Label for="edit-name">Name</Label>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Input id="edit-name" v-model="editForm.name" required autocomplete="name" />
                    <InputError :message="editForm.errors.name" />
                </div>
                <div class="grid gap-2">
                    <Label for="edit-email">Email</Label>
                    <!-- eslint-disable-next-line vue/no-mutating-props -- Inertia's useForm() is shared reactive state, not a one-way prop; the parent and this component intentionally read/write the same form object. -->
                    <Input id="edit-email" v-model="editForm.email" type="email" required autocomplete="email" />
                    <InputError :message="editForm.errors.email" />
                </div>
                <div class="grid gap-2">
                    <Label for="edit-office">School / office</Label>
                    <Select v-model="editOfficeModel">
                        <SelectTrigger id="edit-office" class="w-full">
                            <SelectValue placeholder="None" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem :value="NONE">None</SelectItem>
                            <SelectItem
                                v-for="opt in offices"
                                :key="opt.value"
                                :value="opt.value"
                            >
                                {{ opt.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="editForm.errors.office_id" />
                </div>
                <div class="grid gap-2">
                    <Label>Roles</Label>
                    <CheckboxGroupField
                        :form="editForm"
                        field="role_ids"
                        :options="roleOptions"
                        id-prefix="user-role"
                    />
                    <InputError :message="editForm.errors.role_ids" />
                </div>

                <DialogFooter class="gap-2">
                    <Button type="button" variant="secondary" @click="closeEditDialog">Cancel</Button>
                    <Button type="submit" :disabled="editForm.processing" data-test="save-user-button">
                        <Spinner v-if="editForm.processing" />
                        Save changes
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <Dialog :open="pendingDelete !== null" @update:open="(open) => !open && (pendingDelete = null)">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Delete user?</DialogTitle>
                <DialogDescription>
                    This will permanently delete <strong>{{ pendingDelete?.name }}</strong>'s
                    account. This cannot be undone.
                </DialogDescription>
            </DialogHeader>
            <DialogFooter class="gap-2">
                <Button variant="secondary" @click="pendingDelete = null">Cancel</Button>
                <Button variant="destructive" @click="confirmDelete" data-test="confirm-delete-user-button">
                    Delete
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
