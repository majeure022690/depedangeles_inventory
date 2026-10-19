<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Plus, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
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
import * as equipmentRoutes from '@/routes/equipment';
import type {
    EquipmentFilterOptions,
    EquipmentFilters,
    EquipmentListItem,
} from '@/types/equipment';
import type { Paginated } from '@/types/pagination';

const props = defineProps<{
    equipment: Paginated<EquipmentListItem>;
    filters: EquipmentFilters;
    options: EquipmentFilterOptions;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Equipment', href: equipmentRoutes.index() }],
    },
});

// Display-only sentinel for the "All ..." <Select> option — Reka's Select
// can't bind an empty-string item value, so the trigger needs a concrete
// value to match. The actual filter state driving the URL (`filters.*`)
// stays '' for "all"; only the <Select> `model-value` computeds below ever
// see ALL.
const ALL = '__all__';

const { can } = usePermissions();

const { filters, update } = useTableFilters(equipmentRoutes.index.url(), {
    search: props.filters.search ?? '',
    condition: props.filters.condition ?? '',
    category: props.filters.category ?? '',
    disposition_status: props.filters.disposition_status ?? '',
}, {
    debounceKeys: ['search'],
});

const conditionModel = computed(() => (filters.condition === '' ? ALL : filters.condition));
const categoryModel = computed(() => (filters.category === '' ? ALL : filters.category));
const dispositionStatusModel = computed(() =>
    filters.disposition_status === '' ? ALL : filters.disposition_status,
);

function updateFilter(key: 'condition' | 'category' | 'disposition_status', value: string) {
    update(key, value === ALL ? '' : value);
}

const currencyFormatter = new Intl.NumberFormat('en-PH', {
    style: 'currency',
    currency: 'PHP',
});

const pendingDelete = ref<EquipmentListItem | null>(null);

function requestDelete(row: EquipmentListItem) {
    pendingDelete.value = row;
}

function confirmDelete() {
    if (!pendingDelete.value) {
        return;
    }

    router.delete(equipmentRoutes.destroy.url(pendingDelete.value.id), {
        preserveScroll: true,
        onFinish: () => {
            pendingDelete.value = null;
        },
    });
}
</script>

<template>
    <Head title="Equipment" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <Heading
                title="Equipment"
                description="Manage ICT equipment and asset records."
            />
            <Link v-if="can('equipment.create')" :href="equipmentRoutes.create()">
                <Button data-test="add-equipment-button">
                    <Plus class="size-4" />
                    Add equipment
                </Button>
            </Link>
        </div>

        <div class="flex flex-col gap-3 lg:flex-row lg:items-center">
            <div class="lg:max-w-sm lg:flex-1">
                <Input
                    :model-value="filters.search"
                    type="search"
                    placeholder="Search by property no., item, brand, or serial no..."
                    aria-label="Search equipment"
                    @update:model-value="(value) => update('search', String(value))"
                />
            </div>
            <Select
                :model-value="conditionModel"
                @update:model-value="(value) => updateFilter('condition', String(value))"
            >
                <SelectTrigger class="w-full lg:w-44" aria-label="Filter by condition">
                    <SelectValue placeholder="Condition" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem :value="ALL">All conditions</SelectItem>
                    <SelectItem
                        v-for="opt in options.condition"
                        :key="opt.value"
                        :value="String(opt.value)"
                    >
                        {{ opt.label }}
                    </SelectItem>
                </SelectContent>
            </Select>
            <Select
                :model-value="categoryModel"
                @update:model-value="(value) => updateFilter('category', String(value))"
            >
                <SelectTrigger class="w-full lg:w-44" aria-label="Filter by category">
                    <SelectValue placeholder="Category" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem :value="ALL">All categories</SelectItem>
                    <SelectItem
                        v-for="opt in options.category"
                        :key="opt.value"
                        :value="String(opt.value)"
                    >
                        {{ opt.label }}
                    </SelectItem>
                </SelectContent>
            </Select>
            <Select
                :model-value="dispositionStatusModel"
                @update:model-value="(value) => updateFilter('disposition_status', String(value))"
            >
                <SelectTrigger class="w-full lg:w-48" aria-label="Filter by disposition status">
                    <SelectValue placeholder="Disposition status" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem :value="ALL">All disposition statuses</SelectItem>
                    <SelectItem
                        v-for="opt in options.disposition_status"
                        :key="opt.value"
                        :value="String(opt.value)"
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
                        <th scope="col" class="px-4 py-3 font-medium">Property no.</th>
                        <th scope="col" class="px-4 py-3 font-medium">Item</th>
                        <th scope="col" class="px-4 py-3 font-medium">Condition</th>
                        <th scope="col" class="px-4 py-3 font-medium">Disposition</th>
                        <th scope="col" class="px-4 py-3 font-medium">Accountable officer</th>
                        <th scope="col" class="px-4 py-3 font-medium">End user</th>
                        <th scope="col" class="px-4 py-3 font-medium">Cost</th>
                        <th scope="col" class="px-4 py-3 font-medium">
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-if="props.equipment.data.length === 0">
                        <td colspan="8" class="px-4 py-8 text-center text-muted-foreground">
                            No equipment records found.
                        </td>
                    </tr>
                    <tr v-for="row in props.equipment.data" :key="row.id">
                        <td class="px-4 py-3 font-mono text-xs">
                            <span v-if="row.property_no">{{ row.property_no }}</span>
                            <Badge v-else variant="secondary">Pending</Badge>
                        </td>
                        <td class="px-4 py-3">
                            <div class="font-medium">{{ row.item }}</div>
                            <div class="text-xs text-muted-foreground">
                                {{ row.brand_manufacturer }}
                                <template v-if="row.model">— {{ row.model }}</template>
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            <Badge variant="outline">{{ row.equipment_condition }}</Badge>
                        </td>
                        <td class="px-4 py-3">{{ row.disposition_status }}</td>
                        <td class="px-4 py-3">
                            {{ row.current_accountable_officer?.full_name ?? '—' }}
                        </td>
                        <td class="px-4 py-3">{{ row.current_end_user?.full_name ?? '—' }}</td>
                        <td class="px-4 py-3">
                            {{ row.acquisition_cost !== null ? currencyFormatter.format(row.acquisition_cost) : '—' }}
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center justify-end gap-2">
                                <Link v-if="can('equipment.edit')" :href="equipmentRoutes.edit(row.id)">
                                    <Button variant="outline" size="sm">Edit</Button>
                                </Link>
                                <Button
                                    v-if="can('equipment.delete')"
                                    variant="ghost"
                                    size="sm"
                                    :aria-label="`Delete ${row.property_no ?? 'pending equipment record'}`"
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

        <Pagination :links="props.equipment.links" />
    </div>

    <Dialog :open="pendingDelete !== null" @update:open="(open) => !open && (pendingDelete = null)">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Delete equipment record?</DialogTitle>
                <DialogDescription>
                    This will soft-delete
                    <template v-if="pendingDelete?.property_no">
                        property no. <strong>{{ pendingDelete.property_no }}</strong>
                    </template>
                    <template v-else>this pending equipment record</template>. The
                    record can be restored later if needed, but it will disappear
                    from this list.
                </DialogDescription>
            </DialogHeader>
            <DialogFooter class="gap-2">
                <Button variant="secondary" @click="pendingDelete = null">Cancel</Button>
                <Button variant="destructive" @click="confirmDelete">Delete</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
