<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Plus, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
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
import { Spinner } from '@/components/ui/spinner';
import { usePermissions } from '@/composables/usePermissions';
import { useTableFilters } from '@/composables/useTableFilters';
import * as officesRoutes from '@/routes/offices';
import type { OfficeFilters, OfficeListItem } from '@/types/office';
import type { Paginated } from '@/types/pagination';

const props = defineProps<{
    offices: Paginated<OfficeListItem>;
    filters: OfficeFilters;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Offices', href: officesRoutes.index() }],
    },
});

const { can } = usePermissions();

const { filters, update } = useTableFilters(officesRoutes.index.url(), {
    search: props.filters.search ?? '',
}, {
    debounceKeys: ['search'],
});

const pendingDelete = ref<OfficeListItem | null>(null);

// `delete` can't be a typed useForm() field — it collides with the form
// instance's own `.delete()` submit method — but OfficeController::
// destroy() genuinely uses that key in its ValidationException when the
// office is still FK-referenced by users/stakeholder_profiles/internet_
// connectivity_surveys. Read it off the runtime errors bag directly rather
// than widening the form's data type — mirrors reference-data/Show.vue's
// identical guarded-delete pattern.
const deleteForm = useForm({});
const deleteError = computed(() => (deleteForm.errors as Record<string, string | undefined>).delete);

function requestDelete(row: OfficeListItem) {
    pendingDelete.value = row;
    deleteForm.clearErrors();
}

function closeDeleteDialog() {
    pendingDelete.value = null;
    deleteForm.clearErrors();
}

function confirmDelete() {
    if (!pendingDelete.value) {
        return;
    }

    deleteForm.delete(officesRoutes.destroy.url(pendingDelete.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            pendingDelete.value = null;
        },
    });
}
</script>

<template>
    <Head title="Offices" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <Heading
                title="Offices"
                description="Manage schools and division-level offices/units."
            />
            <Link v-if="can('office.create')" :href="officesRoutes.create()">
                <Button data-test="add-office-button">
                    <Plus class="size-4" />
                    Add office
                </Button>
            </Link>
        </div>

        <div class="flex flex-col gap-3 lg:flex-row lg:items-center">
            <div class="lg:max-w-sm lg:flex-1">
                <Input
                    :model-value="filters.search"
                    type="search"
                    placeholder="Search by office name, type, or School ID..."
                    aria-label="Search offices"
                    @update:model-value="(value) => update('search', String(value))"
                />
            </div>
        </div>

        <div class="overflow-x-auto rounded-lg border">
            <table class="w-full text-left text-sm">
                <thead class="border-b bg-muted/50 text-xs uppercase text-muted-foreground">
                    <tr>
                        <th scope="col" class="px-4 py-3 font-medium">Office name</th>
                        <th scope="col" class="px-4 py-3 font-medium">Type</th>
                        <th scope="col" class="px-4 py-3 font-medium">School ID</th>
                        <th scope="col" class="px-4 py-3 font-medium">District</th>
                        <th scope="col" class="px-4 py-3 font-medium">Division</th>
                        <th scope="col" class="px-4 py-3 font-medium">Region</th>
                        <th scope="col" class="px-4 py-3 font-medium">Status</th>
                        <th scope="col" class="px-4 py-3 font-medium">
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-if="props.offices.data.length === 0">
                        <td colspan="8" class="px-4 py-8 text-center text-muted-foreground">
                            No offices found.
                        </td>
                    </tr>
                    <tr v-for="row in props.offices.data" :key="row.id">
                        <td class="px-4 py-3 font-medium">{{ row.office_name }}</td>
                        <td class="px-4 py-3 text-muted-foreground">{{ row.office_type ?? '—' }}</td>
                        <td class="px-4 py-3 font-mono text-xs">{{ row.school_id ?? '—' }}</td>
                        <td class="px-4 py-3 text-muted-foreground">{{ row.district ?? '—' }}</td>
                        <td class="px-4 py-3 text-muted-foreground">{{ row.division ?? '—' }}</td>
                        <td class="px-4 py-3 text-muted-foreground">{{ row.region ?? '—' }}</td>
                        <td class="px-4 py-3">
                            <Badge :variant="row.is_active ? 'secondary' : 'destructive'">
                                {{ row.is_active ? 'Active' : 'Inactive' }}
                            </Badge>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center justify-end gap-2">
                                <Link v-if="can('office.edit')" :href="officesRoutes.edit(row.id)">
                                    <Button variant="outline" size="sm">Edit</Button>
                                </Link>
                                <Button
                                    v-if="can('office.delete')"
                                    variant="ghost"
                                    size="sm"
                                    :aria-label="`Delete ${row.office_name}`"
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

        <Pagination :links="props.offices.links" />
    </div>

    <Dialog :open="pendingDelete !== null" @update:open="(open) => !open && closeDeleteDialog()">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Delete office?</DialogTitle>
                <DialogDescription>
                    This will permanently delete
                    <strong>{{ pendingDelete?.office_name }}</strong>. This cannot be
                    undone. If this office is still referenced by user accounts,
                    stakeholder profiles, or connectivity surveys, deactivate it
                    instead.
                </DialogDescription>
            </DialogHeader>
            <InputError :message="deleteError" />
            <DialogFooter class="gap-2">
                <Button type="button" variant="secondary" @click="closeDeleteDialog">Cancel</Button>
                <Button
                    type="button"
                    variant="destructive"
                    :disabled="deleteForm.processing"
                    data-test="confirm-delete-office-button"
                    @click="confirmDelete"
                >
                    <Spinner v-if="deleteForm.processing" />
                    Delete
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
