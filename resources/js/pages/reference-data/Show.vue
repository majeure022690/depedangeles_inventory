<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Plus, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import Pagination from '@/components/Pagination.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import { Textarea } from '@/components/ui/textarea';
import { useTableFilters } from '@/composables/useTableFilters';
import referenceData from '@/routes/reference-data';
import type { LookupOption } from '@/types/lookup';
import type { Paginated } from '@/types/pagination';
import type {
    ReferenceDataCreateFormData,
    ReferenceDataFilters,
    ReferenceDataFormData,
    ReferenceDataTier1Row,
    ReferenceDataTier2Row,
} from '@/types/reference-data';

const props = defineProps<{
    table: string;
    label: string;
    tier: 1 | 2;
    rows: Paginated<ReferenceDataTier1Row | ReferenceDataTier2Row>;
    types: LookupOption[] | null;
    filters: ReferenceDataFilters;
}>();

// A callback, not a static object — `defineOptions()` is hoisted to module
// scope by the <script setup> compiler and can't close over `props`, but
// Inertia's layout resolver also accepts a function of the page's raw
// props (see @inertiajs/vue3's App.vue render logic) which runs at render
// time instead, letting the second breadcrumb reflect whichever table
// `{table}`/`{label}` this page instance was rendered for.
defineOptions({
    layout: (pageProps: { label: string; table: string }) => ({
        breadcrumbs: [
            { title: 'Reference Data', href: referenceData.index() },
            { title: pageProps.label, href: referenceData.show.url(pageProps.table) },
        ],
    }),
});

const isTier1 = computed(() => props.tier === 1);

// The backend ships tier-appropriate row shapes for the same `rows` prop
// (see ReferenceDataController::show()) — these casts just let the
// template read the tier-specific fields without a per-row type predicate;
// which branch actually renders is always gated on `isTier1` below.
const tier1Rows = computed(() => props.rows.data as ReferenceDataTier1Row[]);
const tier2Rows = computed(() => props.rows.data as ReferenceDataTier2Row[]);

const ALL = '__all__';

const { filters, update } = useTableFilters(referenceData.show.url(props.table), {
    search: props.filters.search ?? '',
    type: props.filters.type ?? ALL,
});

function updateTypeFilter(value: string) {
    update('type', value === ALL ? '' : value);
}

const typeLabels = computed(() => new Map((props.types ?? []).map((type) => [type.value, type.label])));

function typeLabel(type: string): string {
    return typeLabels.value.get(type) ?? type;
}

const editingRow = ref<ReferenceDataTier1Row | ReferenceDataTier2Row | null>(null);

const form = useForm<ReferenceDataFormData>({
    name: '',
    description: '',
    sort_order: '0',
    is_active: true,
});

function editRow(row: ReferenceDataTier1Row | ReferenceDataTier2Row) {
    editingRow.value = row;
    form.clearErrors();
    form.defaults({
        name: isTier1.value ? (row as ReferenceDataTier1Row).name : '',
        description: row.description ?? '',
        sort_order: String(row.sort_order),
        is_active: row.is_active,
    });
    form.reset();
}

function closeDialog() {
    editingRow.value = null;
    form.clearErrors();
}

function submit() {
    if (!editingRow.value) {
        return;
    }

    form.patch(referenceData.update.url([props.table, editingRow.value.id]), {
        preserveScroll: true,
        onSuccess: () => {
            editingRow.value = null;
        },
    });
}

const isCreating = ref(false);

const createForm = useForm<ReferenceDataCreateFormData>({
    name: '',
    type: undefined,
    value: '',
    description: '',
    sort_order: '0',
    is_active: true,
});

function openCreateDialog() {
    isCreating.value = true;
    createForm.clearErrors();
    createForm.defaults({
        name: '',
        type: undefined,
        value: '',
        description: '',
        sort_order: '0',
        is_active: true,
    });
    createForm.reset();
}

function closeCreateDialog() {
    isCreating.value = false;
    createForm.clearErrors();
}

function submitCreate() {
    createForm.post(referenceData.store.url(props.table), {
        preserveScroll: true,
        onSuccess: () => {
            isCreating.value = false;
        },
    });
}

const pendingDelete = ref<ReferenceDataTier1Row | ReferenceDataTier2Row | null>(null);

// `delete` can't be a typed useForm() field — it collides with the form
// instance's own `.delete()` submit method — but ReferenceDataController::
// destroy() genuinely uses that key in its ValidationException (see
// guardDeletable()'s "still in use" message). Read it off the runtime
// errors bag directly rather than widening the form's data type.
const deleteForm = useForm({});
const deleteError = computed(() => (deleteForm.errors as Record<string, string | undefined>).delete);

function requestDelete(row: ReferenceDataTier1Row | ReferenceDataTier2Row) {
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

    deleteForm.delete(referenceData.destroy.url([props.table, pendingDelete.value.id]), {
        preserveScroll: true,
        onSuccess: () => {
            pendingDelete.value = null;
        },
    });
}

function rowLabel(row: ReferenceDataTier1Row | ReferenceDataTier2Row): string {
    return isTier1.value ? (row as ReferenceDataTier1Row).name : (row as ReferenceDataTier2Row).value;
}
</script>

<template>
    <Head :title="props.label" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <Heading
                :title="props.label"
                :description="
                    isTier1
                        ? 'Dedicated reference table — name, description, sort order, and active status are editable.'
                        : 'Grouped library table — type and value are permanently fixed; description, sort order, and active status are editable.'
                "
            />
            <Button data-test="add-reference-data-button" @click="openCreateDialog">
                <Plus class="size-4" />
                Add new
            </Button>
        </div>

        <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
            <div class="sm:max-w-sm sm:flex-1">
                <Input
                    :model-value="filters.search"
                    type="search"
                    :placeholder="isTier1 ? 'Search by name or description...' : 'Search by value or description...'"
                    aria-label="Search"
                    @update:model-value="(value) => update('search', String(value))"
                />
            </div>
            <Select
                v-if="!isTier1 && props.types"
                :model-value="filters.type"
                @update:model-value="(value) => updateTypeFilter(String(value))"
            >
                <SelectTrigger class="w-full sm:w-56" aria-label="Filter by type">
                    <SelectValue placeholder="Type" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem :value="ALL">All types</SelectItem>
                    <SelectItem v-for="opt in props.types" :key="opt.value" :value="opt.value">
                        {{ opt.label }}
                    </SelectItem>
                </SelectContent>
            </Select>
        </div>

        <div class="overflow-x-auto rounded-lg border">
            <table class="w-full text-left text-sm">
                <thead class="border-b bg-muted/50 text-xs uppercase text-muted-foreground">
                    <tr>
                        <th v-if="!isTier1" scope="col" class="px-4 py-3 font-medium">Type</th>
                        <th scope="col" class="px-4 py-3 font-medium">{{ isTier1 ? 'Name' : 'Value' }}</th>
                        <th scope="col" class="px-4 py-3 font-medium">Description</th>
                        <th scope="col" class="px-4 py-3 font-medium">Sort order</th>
                        <th scope="col" class="px-4 py-3 font-medium">Status</th>
                        <th scope="col" class="px-4 py-3 font-medium">
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-if="props.rows.data.length === 0">
                        <td :colspan="isTier1 ? 5 : 6" class="px-4 py-8 text-center text-muted-foreground">
                            No rows found.
                        </td>
                    </tr>
                    <template v-if="isTier1">
                        <tr v-for="row in tier1Rows" :key="row.id">
                            <td class="px-4 py-3 font-medium">{{ row.name }}</td>
                            <td class="px-4 py-3 text-muted-foreground">{{ row.description ?? '—' }}</td>
                            <td class="px-4 py-3 tabular-nums">{{ row.sort_order }}</td>
                            <td class="px-4 py-3">
                                <Badge :variant="row.is_active ? 'secondary' : 'destructive'">
                                    {{ row.is_active ? 'Active' : 'Inactive' }}
                                </Badge>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex items-center justify-end gap-2">
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        :aria-label="`Edit ${row.name}`"
                                        @click="editRow(row)"
                                    >
                                        Edit
                                    </Button>
                                    <Button
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
                    </template>
                    <template v-else>
                        <tr v-for="row in tier2Rows" :key="row.id">
                            <td class="px-4 py-3 text-muted-foreground">{{ typeLabel(row.type) }}</td>
                            <td class="px-4 py-3 font-medium">{{ row.value }}</td>
                            <td class="px-4 py-3 text-muted-foreground">{{ row.description ?? '—' }}</td>
                            <td class="px-4 py-3 tabular-nums">{{ row.sort_order }}</td>
                            <td class="px-4 py-3">
                                <Badge :variant="row.is_active ? 'secondary' : 'destructive'">
                                    {{ row.is_active ? 'Active' : 'Inactive' }}
                                </Badge>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex items-center justify-end gap-2">
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        :aria-label="`Edit ${row.value}`"
                                        @click="editRow(row)"
                                    >
                                        Edit
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        :aria-label="`Delete ${row.value}`"
                                        @click="requestDelete(row)"
                                    >
                                        <Trash2 class="size-4 text-destructive" />
                                    </Button>
                                </div>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        <Pagination :links="props.rows.links" />
    </div>

    <Dialog :open="editingRow !== null" @update:open="(open) => !open && closeDialog()">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Edit {{ isTier1 ? 'reference row' : 'library value' }}</DialogTitle>
                <DialogDescription v-if="isTier1">
                    Rename this entry or update its description, sort order, and active status.
                    Every record that references it displays the updated name immediately.
                </DialogDescription>
                <DialogDescription v-else>
                    Only the description, sort order, and active status can be changed here. The
                    type and value are fixed to keep historical records intact.
                </DialogDescription>
            </DialogHeader>

            <form v-if="editingRow" class="space-y-4" @submit.prevent="submit">
                <template v-if="isTier1">
                    <div class="grid gap-2">
                        <Label for="name">Name</Label>
                        <Input id="name" v-model="form.name" />
                        <InputError :message="form.errors.name" />
                    </div>
                </template>
                <template v-else>
                    <div class="grid grid-cols-2 gap-4 text-sm">
                        <div class="space-y-1">
                            <p class="text-muted-foreground">Type</p>
                            <p class="font-medium">{{ typeLabel((editingRow as ReferenceDataTier2Row).type) }}</p>
                        </div>
                        <div class="space-y-1">
                            <p class="text-muted-foreground">Value</p>
                            <p class="font-medium">{{ (editingRow as ReferenceDataTier2Row).value }}</p>
                        </div>
                    </div>
                </template>

                <div class="grid gap-2">
                    <Label for="description">Description</Label>
                    <Textarea id="description" v-model="form.description" rows="3" />
                    <InputError :message="form.errors.description" />
                </div>

                <div class="grid gap-2">
                    <Label for="sort_order">Sort order</Label>
                    <Input id="sort_order" v-model="form.sort_order" type="number" min="0" />
                    <InputError :message="form.errors.sort_order" />
                </div>

                <div class="flex items-center gap-2">
                    <Checkbox id="is_active" v-model="form.is_active" />
                    <Label for="is_active">Active</Label>
                </div>
                <InputError :message="form.errors.is_active" />

                <DialogFooter class="gap-2">
                    <Button type="button" variant="secondary" @click="closeDialog">Cancel</Button>
                    <Button type="submit" :disabled="form.processing" data-test="update-reference-data-button">
                        <Spinner v-if="form.processing" />
                        Save changes
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <Dialog :open="isCreating" @update:open="(open) => !open && closeCreateDialog()">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Add {{ isTier1 ? 'reference row' : 'library value' }}</DialogTitle>
                <DialogDescription v-if="isTier1">
                    Add a new entry to this reference table. The name must be unique within the
                    table.
                </DialogDescription>
                <DialogDescription v-else>
                    Add a new value under a type in this library table. The type cannot be changed
                    after creation, and the value must be unique within that type.
                </DialogDescription>
            </DialogHeader>

            <form class="space-y-4" @submit.prevent="submitCreate">
                <template v-if="isTier1">
                    <div class="grid gap-2">
                        <Label for="create-name">Name</Label>
                        <Input id="create-name" v-model="createForm.name" required autofocus />
                        <InputError :message="createForm.errors.name" />
                    </div>
                </template>
                <template v-else>
                    <div class="grid gap-2">
                        <Label for="create-type">Type</Label>
                        <Select v-model="createForm.type">
                            <SelectTrigger id="create-type" class="w-full" aria-label="Type">
                                <SelectValue placeholder="Select a type" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem v-for="opt in props.types ?? []" :key="opt.value" :value="opt.value">
                                    {{ opt.label }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="createForm.errors.type" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="create-value">Value</Label>
                        <Input id="create-value" v-model="createForm.value" required autofocus />
                        <InputError :message="createForm.errors.value" />
                    </div>
                </template>

                <div class="grid gap-2">
                    <Label for="create-description">Description</Label>
                    <Textarea id="create-description" v-model="createForm.description" rows="3" />
                    <InputError :message="createForm.errors.description" />
                </div>

                <div class="grid gap-2">
                    <Label for="create-sort_order">Sort order</Label>
                    <Input id="create-sort_order" v-model="createForm.sort_order" type="number" min="0" />
                    <InputError :message="createForm.errors.sort_order" />
                </div>

                <div class="flex items-center gap-2">
                    <Checkbox id="create-is_active" v-model="createForm.is_active" />
                    <Label for="create-is_active">Active</Label>
                </div>
                <InputError :message="createForm.errors.is_active" />

                <DialogFooter class="gap-2">
                    <Button type="button" variant="secondary" @click="closeCreateDialog">Cancel</Button>
                    <Button type="submit" :disabled="createForm.processing" data-test="create-reference-data-button">
                        <Spinner v-if="createForm.processing" />
                        Add
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <Dialog :open="pendingDelete !== null" @update:open="(open) => !open && closeDeleteDialog()">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Delete {{ isTier1 ? 'reference row' : 'library value' }}?</DialogTitle>
                <DialogDescription>
                    This will permanently delete
                    <strong>{{ pendingDelete ? rowLabel(pendingDelete) : '' }}</strong>. This cannot
                    be undone.
                </DialogDescription>
            </DialogHeader>
            <InputError :message="deleteError" />
            <DialogFooter class="gap-2">
                <Button type="button" variant="secondary" @click="closeDeleteDialog">Cancel</Button>
                <Button
                    type="button"
                    variant="destructive"
                    :disabled="deleteForm.processing"
                    data-test="confirm-delete-reference-data-button"
                    @click="confirmDelete"
                >
                    <Spinner v-if="deleteForm.processing" />
                    Delete
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
