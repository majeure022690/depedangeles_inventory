<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Plus, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
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
import { usePermissions } from '@/composables/usePermissions';
import { useTableFilters } from '@/composables/useTableFilters';
import * as personnelRoutes from '@/routes/personnel';
import type { Paginated } from '@/types/pagination';
import type {
    PersonnelFilters,
    PersonnelListItem,
    PersonnelStatusFilter,
} from '@/types/personnel';

const props = defineProps<{
    personnel: Paginated<PersonnelListItem>;
    filters: PersonnelFilters;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Personnel', href: personnelRoutes.index() }],
    },
});

const { can } = usePermissions();

const { filters, update } = useTableFilters(personnelRoutes.index.url(), {
    search: props.filters.search ?? '',
    status: props.filters.status,
}, {
    debounceKeys: ['search'],
});

const statusOptions: { value: PersonnelStatusFilter; label: string }[] = [
    { value: 'active', label: 'Active' },
    { value: 'inactive', label: 'Inactive' },
    { value: 'all', label: 'All' },
];

const pendingDelete = ref<PersonnelListItem | null>(null);

function requestDelete(row: PersonnelListItem) {
    pendingDelete.value = row;
}

function confirmDelete() {
    if (!pendingDelete.value) {
        return;
    }

    router.delete(personnelRoutes.destroy.url(pendingDelete.value.id), {
        preserveScroll: true,
        onFinish: () => {
            pendingDelete.value = null;
        },
    });
}
</script>

<template>
    <Head title="Personnel" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <Heading
                title="Personnel"
                description="Manage division personnel records."
            />
            <Link v-if="can('personnel.create')" :href="personnelRoutes.create()">
                <Button data-test="add-personnel-button">
                    <Plus class="size-4" />
                    Add personnel
                </Button>
            </Link>
        </div>

        <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
            <div class="sm:max-w-sm sm:flex-1">
                <Input
                    :model-value="filters.search"
                    type="search"
                    placeholder="Search by name, employee ID, or email..."
                    aria-label="Search personnel"
                    @update:model-value="(value) => update('search', String(value))"
                />
            </div>
            <Select
                :model-value="filters.status"
                @update:model-value="(value) => update('status', value as PersonnelStatusFilter)"
            >
                <SelectTrigger class="w-full sm:w-40" aria-label="Filter by status">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="opt in statusOptions"
                        :key="opt.value"
                        :value="opt.value"
                    >
                        {{ opt.label }}
                    </SelectItem>
                </SelectContent>
            </Select>
        </div>

        <div class="overflow-x-auto rounded-lg border">
            <table class="w-full text-left text-sm">
                <thead class="border-b bg-muted/50 text-xs uppercase text-muted-foreground">
                    <tr>
                        <th scope="col" class="px-4 py-3 font-medium">Employee ID</th>
                        <th scope="col" class="px-4 py-3 font-medium">Name</th>
                        <th scope="col" class="px-4 py-3 font-medium">Position</th>
                        <th scope="col" class="px-4 py-3 font-medium">Office</th>
                        <th scope="col" class="px-4 py-3 font-medium">Mobile</th>
                        <th scope="col" class="px-4 py-3 font-medium">Status</th>
                        <th scope="col" class="px-4 py-3 font-medium">
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-if="props.personnel.data.length === 0">
                        <td colspan="7" class="px-4 py-8 text-center text-muted-foreground">
                            No personnel records found.
                        </td>
                    </tr>
                    <tr v-for="row in props.personnel.data" :key="row.id">
                        <td class="px-4 py-3 font-mono text-xs">{{ row.employee_id }}</td>
                        <td class="px-4 py-3">
                            <div class="font-medium">{{ row.full_name }}</div>
                            <div v-if="row.oic" class="text-xs text-muted-foreground">
                                OIC{{ row.oic_office ? ` — ${row.oic_office}` : '' }}
                            </div>
                            <div v-if="row.deped_email" class="text-xs text-muted-foreground">
                                {{ row.deped_email }}
                            </div>
                        </td>
                        <td class="px-4 py-3">{{ row.position ?? '—' }}</td>
                        <td class="px-4 py-3">{{ row.division_unit ?? row.ro_division ?? '—' }}</td>
                        <td class="px-4 py-3">{{ row.mobile_1 ?? '—' }}</td>
                        <td class="px-4 py-3">
                            <Badge :variant="row.inactive ? 'destructive' : 'secondary'">
                                {{ row.inactive ? 'Inactive' : 'Active' }}
                            </Badge>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center justify-end gap-2">
                                <Link v-if="can('personnel.edit')" :href="personnelRoutes.edit(row.id)">
                                    <Button variant="outline" size="sm">Edit</Button>
                                </Link>
                                <Button
                                    v-if="can('personnel.delete')"
                                    variant="ghost"
                                    size="sm"
                                    :aria-label="`Delete ${row.full_name}`"
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

        <Pagination :links="props.personnel.links" />
    </div>

    <Dialog :open="pendingDelete !== null" @update:open="(open) => !open && (pendingDelete = null)">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Delete personnel record?</DialogTitle>
                <DialogDescription>
                    This will soft-delete
                    <strong>{{ pendingDelete?.full_name }}</strong>. The record can
                    be restored later if needed, but it will disappear from this
                    list.
                </DialogDescription>
            </DialogHeader>
            <DialogFooter class="gap-2">
                <Button variant="secondary" @click="pendingDelete = null">Cancel</Button>
                <Button variant="destructive" @click="confirmDelete">Delete</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
