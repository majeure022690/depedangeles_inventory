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
import * as ispAccountsRoutes from '@/routes/isp-accounts';
import type {
    IspAccountFilterOptions,
    IspAccountFilters,
    IspAccountListItem,
} from '@/types/isp-account';
import type { Paginated } from '@/types/pagination';

const props = defineProps<{
    ispAccounts: Paginated<IspAccountListItem>;
    filters: IspAccountFilters;
    options: IspAccountFilterOptions;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'ISP Accounts', href: ispAccountsRoutes.index() }],
    },
});

// Display-only sentinel for the "All ..." <Select> option — the actual
// filter state driving the URL (`filters.*`) stays '' for "all"; only the
// <Select> `model-value` computeds below ever see ALL.
const ALL = '__all__';

const { can } = usePermissions();

const { filters, update } = useTableFilters(ispAccountsRoutes.index.url(), {
    search: props.filters.search ?? '',
    isp: props.filters.isp ?? '',
    status: props.filters.status ?? '',
}, {
    debounceKeys: ['search'],
});

const ispModel = computed(() => (filters.isp === '' ? ALL : filters.isp));
const statusModel = computed(() => (filters.status === '' ? ALL : filters.status));

function updateFilter(key: 'isp' | 'status', value: string) {
    update(key, value === ALL ? '' : value);
}

const statusOptions = [
    { value: 'active', label: 'Active' },
    { value: 'inactive', label: 'Inactive' },
];

const currencyFormatter = new Intl.NumberFormat('en-PH', {
    style: 'currency',
    currency: 'PHP',
});

const pendingDelete = ref<IspAccountListItem | null>(null);

function requestDelete(row: IspAccountListItem) {
    pendingDelete.value = row;
}

function confirmDelete() {
    if (!pendingDelete.value) {
        return;
    }

    router.delete(ispAccountsRoutes.destroy.url(pendingDelete.value.id), {
        preserveScroll: true,
        onFinish: () => {
            pendingDelete.value = null;
        },
    });
}
</script>

<template>
    <Head title="ISP Accounts" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <Heading
                title="ISP Accounts"
                description="Manage internet service provider subscriptions and contracts."
            />
            <Link v-if="can('isp_accounts.create')" :href="ispAccountsRoutes.create()">
                <Button data-test="add-isp-account-button">
                    <Plus class="size-4" />
                    Add ISP account
                </Button>
            </Link>
        </div>

        <div class="flex flex-col gap-3 lg:flex-row lg:items-center">
            <div class="lg:max-w-sm lg:flex-1">
                <Input
                    :model-value="filters.search"
                    type="search"
                    placeholder="Search by ISP or billing account no..."
                    aria-label="Search ISP accounts"
                    @update:model-value="(value) => update('search', String(value))"
                />
            </div>
            <Select
                :model-value="ispModel"
                @update:model-value="(value) => updateFilter('isp', String(value))"
            >
                <SelectTrigger class="w-full lg:w-48" aria-label="Filter by ISP provider">
                    <SelectValue placeholder="ISP provider" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem :value="ALL">All providers</SelectItem>
                    <SelectItem
                        v-for="opt in options.isp_provider"
                        :key="opt.value"
                        :value="String(opt.value)"
                    >
                        {{ opt.label }}
                    </SelectItem>
                </SelectContent>
            </Select>
            <Select
                :model-value="statusModel"
                @update:model-value="(value) => updateFilter('status', String(value))"
            >
                <SelectTrigger class="w-full lg:w-44" aria-label="Filter by contract status">
                    <SelectValue placeholder="Status" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem :value="ALL">All statuses</SelectItem>
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
                        <th scope="col" class="px-4 py-3 font-medium">ISP</th>
                        <th scope="col" class="px-4 py-3 font-medium">Account no.</th>
                        <th scope="col" class="px-4 py-3 font-medium">Subscription type</th>
                        <th scope="col" class="px-4 py-3 font-medium">Connection type</th>
                        <th scope="col" class="px-4 py-3 font-medium">Cost/month</th>
                        <th scope="col" class="px-4 py-3 font-medium">Contract period</th>
                        <th scope="col" class="px-4 py-3 font-medium">Signal quality</th>
                        <th scope="col" class="px-4 py-3 font-medium">Status</th>
                        <th scope="col" class="px-4 py-3 font-medium">
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-if="props.ispAccounts.data.length === 0">
                        <td colspan="9" class="px-4 py-8 text-center text-muted-foreground">
                            No ISP account records found.
                        </td>
                    </tr>
                    <tr v-for="row in props.ispAccounts.data" :key="row.id">
                        <td class="px-4 py-3 font-medium">{{ row.isp }}</td>
                        <td class="px-4 py-3 font-mono text-xs">{{ row.isp_billing_account_no }}</td>
                        <td class="px-4 py-3">{{ row.subscription_type }}</td>
                        <td class="px-4 py-3">{{ row.isp_connection_type }}</td>
                        <td class="px-4 py-3">{{ currencyFormatter.format(row.cost_per_month) }}</td>
                        <td class="px-4 py-3 text-xs text-muted-foreground">
                            {{ row.contract_start_date ?? '—' }} to {{ row.contract_end_date ?? '—' }}
                        </td>
                        <td class="px-4 py-3">
                            <Badge variant="outline">{{ row.overall_signal_quality }}</Badge>
                        </td>
                        <td class="px-4 py-3">
                            <Badge :variant="row.inactive_contract ? 'destructive' : 'secondary'">
                                {{ row.inactive_contract ? 'Inactive' : 'Active' }}
                            </Badge>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center justify-end gap-2">
                                <Link v-if="can('isp_accounts.edit')" :href="ispAccountsRoutes.edit(row.id)">
                                    <Button variant="outline" size="sm">Edit</Button>
                                </Link>
                                <Button
                                    v-if="can('isp_accounts.delete')"
                                    variant="ghost"
                                    size="sm"
                                    :aria-label="`Delete ${row.isp} — ${row.isp_billing_account_no}`"
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

        <Pagination :links="props.ispAccounts.links" />
    </div>

    <Dialog :open="pendingDelete !== null" @update:open="(open) => !open && (pendingDelete = null)">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Delete ISP account record?</DialogTitle>
                <DialogDescription>
                    This will soft-delete the
                    <strong>{{ pendingDelete?.isp }}</strong> account
                    (billing no. {{ pendingDelete?.isp_billing_account_no }}). The
                    record can be restored later if needed, but it will
                    disappear from this list.
                </DialogDescription>
            </DialogHeader>
            <DialogFooter class="gap-2">
                <Button variant="secondary" @click="pendingDelete = null">Cancel</Button>
                <Button variant="destructive" @click="confirmDelete">Delete</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
