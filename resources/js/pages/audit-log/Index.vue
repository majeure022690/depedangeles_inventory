<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import Pagination from '@/components/Pagination.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
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
import { useTableFilters } from '@/composables/useTableFilters';
import auditLog from '@/routes/audit-log';
import type { AuditLogEntry, AuditLogFilterOptions, AuditLogFilters } from '@/types/audit-log';
import type { Paginated } from '@/types/pagination';

const props = defineProps<{
    logs: Paginated<AuditLogEntry>;
    filterOptions: AuditLogFilterOptions;
    filters: AuditLogFilters;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Audit Log', href: auditLog.index() }],
    },
});

// Display-only sentinel for the "All ..." <Select> options — Reka's Select
// can't bind an empty-string item value, so the trigger needs a concrete
// value to match. Same pattern as equipment/Index.vue and reference-data/
// Show.vue. The actual filter state driving the URL (`filters.*`) stays ''
// for "all"; only the <Select> `model-value` computeds below ever see ALL.
const ALL = '__all__';

const { filters, update } = useTableFilters(auditLog.index.url(), {
    search: props.filters.search ?? '',
    action: props.filters.action ?? '',
    subject_type: props.filters.subject_type ?? '',
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
}, {
    debounceKeys: ['search', 'from', 'to'],
});

const actionModel = computed(() => (filters.action === '' ? ALL : filters.action));
const subjectTypeModel = computed(() => (filters.subject_type === '' ? ALL : filters.subject_type));

function updateFilter(key: 'action' | 'subject_type', value: string) {
    update(key, value === ALL ? '' : value);
}

const selectedLog = ref<AuditLogEntry | null>(null);

function viewDetails(row: AuditLogEntry) {
    selectedLog.value = row;
}

function closeDetails() {
    selectedLog.value = null;
}
</script>

<template>
    <Head title="Audit Log" />

    <div class="flex flex-col gap-6 p-4">
        <Heading
            title="Audit Log"
            description="Read-only, append-only trail of actions taken across the system."
        />

        <div class="flex flex-col gap-3 lg:flex-row lg:flex-wrap lg:items-center">
            <div class="lg:max-w-sm lg:flex-1">
                <Input
                    :model-value="filters.search"
                    type="search"
                    placeholder="Search by action or actor name/email..."
                    aria-label="Search audit log"
                    @update:model-value="(value) => update('search', String(value))"
                />
            </div>
            <Select
                :model-value="actionModel"
                @update:model-value="(value) => updateFilter('action', String(value))"
            >
                <SelectTrigger class="w-full lg:w-56" aria-label="Filter by action">
                    <SelectValue placeholder="Action" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem :value="ALL">All actions</SelectItem>
                    <SelectItem
                        v-for="action in props.filterOptions.actions"
                        :key="action"
                        :value="action"
                    >
                        {{ action }}
                    </SelectItem>
                </SelectContent>
            </Select>
            <Select
                :model-value="subjectTypeModel"
                @update:model-value="(value) => updateFilter('subject_type', String(value))"
            >
                <SelectTrigger class="w-full lg:w-56" aria-label="Filter by subject type">
                    <SelectValue placeholder="Subject type" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem :value="ALL">All subject types</SelectItem>
                    <SelectItem
                        v-for="opt in props.filterOptions.subjectTypes"
                        :key="opt.value"
                        :value="opt.value"
                    >
                        {{ opt.label }}
                    </SelectItem>
                </SelectContent>
            </Select>
            <div class="flex items-center gap-2">
                <Input
                    :model-value="filters.from"
                    type="date"
                    aria-label="From date"
                    class="w-full lg:w-40"
                    @update:model-value="(value) => update('from', String(value))"
                />
                <span class="text-sm text-muted-foreground">to</span>
                <Input
                    :model-value="filters.to"
                    type="date"
                    aria-label="To date"
                    class="w-full lg:w-40"
                    @update:model-value="(value) => update('to', String(value))"
                />
            </div>
        </div>

        <div class="overflow-x-auto rounded-lg border">
            <table class="w-full text-left text-sm">
                <thead class="border-b bg-muted/50 text-xs uppercase text-muted-foreground">
                    <tr>
                        <th scope="col" class="px-4 py-3 font-medium">Timestamp</th>
                        <th scope="col" class="px-4 py-3 font-medium">Actor</th>
                        <th scope="col" class="px-4 py-3 font-medium">Action</th>
                        <th scope="col" class="px-4 py-3 font-medium">Subject</th>
                        <th scope="col" class="px-4 py-3 font-medium">
                            <span class="sr-only">Details</span>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-if="props.logs.data.length === 0">
                        <td colspan="5" class="px-4 py-8 text-center text-muted-foreground">
                            No audit log entries found.
                        </td>
                    </tr>
                    <tr v-for="row in props.logs.data" :key="row.id">
                        <td class="px-4 py-3 whitespace-nowrap text-muted-foreground">
                            {{ row.created_at ? new Date(row.created_at).toLocaleString() : '—' }}
                        </td>
                        <td class="px-4 py-3">
                            <template v-if="row.actor">
                                <div class="font-medium">{{ row.actor.name ?? row.actor.email ?? 'Unknown' }}</div>
                                <div v-if="row.actor.email" class="text-xs text-muted-foreground">
                                    {{ row.actor.email }}
                                </div>
                            </template>
                            <span v-else class="text-muted-foreground">System</span>
                        </td>
                        <td class="px-4 py-3">
                            <Badge variant="outline" class="font-mono">{{ row.action }}</Badge>
                        </td>
                        <td class="px-4 py-3 text-muted-foreground">
                            <span v-if="row.subject_type">{{ row.subject_type }} #{{ row.subject_id }}</span>
                            <span v-else>—</span>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center justify-end">
                                <Button variant="outline" size="sm" @click="viewDetails(row)">
                                    View details
                                </Button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination :links="props.logs.links" />
    </div>

    <Dialog :open="selectedLog !== null" @update:open="(open) => !open && closeDetails()">
        <DialogContent class="sm:max-w-2xl">
            <DialogHeader>
                <DialogTitle>
                    <Badge variant="outline" class="font-mono">{{ selectedLog?.action }}</Badge>
                </DialogTitle>
                <DialogDescription>
                    <template v-if="selectedLog">
                        {{ selectedLog.created_at ? new Date(selectedLog.created_at).toLocaleString() : '—' }}
                        &mdash;
                        {{ selectedLog.actor?.name ?? selectedLog.actor?.email ?? 'System' }}
                        <template v-if="selectedLog.subject_type">
                            &mdash; {{ selectedLog.subject_type }} #{{ selectedLog.subject_id }}
                        </template>
                    </template>
                </DialogDescription>
            </DialogHeader>
            <pre class="max-h-96 overflow-y-auto rounded-md bg-muted p-4 text-xs">{{ JSON.stringify(selectedLog?.properties, null, 2) }}</pre>
        </DialogContent>
    </Dialog>
</template>
