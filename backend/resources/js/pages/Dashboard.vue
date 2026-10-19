<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Activity, HardDrive, Sparkles, Users, Wifi } from '@lucide/vue';
import { computed } from 'vue';
import BreakdownList from '@/components/dashboard/BreakdownList.vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { dashboard } from '@/routes';
import type {
    DashboardEquipmentSummary,
    DashboardIspAccountsSummary,
    DashboardPersonnelSummary,
    DashboardRecentActivityEntry,
} from '@/types/dashboard';

// Every key below is OMITTED by DashboardController (not null, not an
// empty shape) when the current user lacks the matching view permission —
// so every section in the template below is gated on key *presence*
// (`v-if="equipment"`), never on a falsy check.
const props = defineProps<{
    equipment?: DashboardEquipmentSummary;
    personnel?: DashboardPersonnelSummary;
    ispAccounts?: DashboardIspAccountsSummary;
    recentActivity?: DashboardRecentActivityEntry[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: dashboard(),
            },
        ],
    },
});

const hasAnySection = computed(
    () => props.equipment !== undefined || props.personnel !== undefined || props.ispAccounts !== undefined,
);

const currencyFormatter = new Intl.NumberFormat('en-PH', {
    style: 'currency',
    currency: 'PHP',
});

function formatDateTime(value: string | null): string {
    return value ? new Date(value).toLocaleString() : '—';
}
</script>

<template>
    <Head title="Dashboard" />

    <div class="flex flex-col gap-6 p-4">
        <Heading title="Dashboard" description="An at-a-glance summary of division ICT inventory." />

        <div v-if="!hasAnySection" class="flex flex-1 items-center justify-center py-16">
            <div class="flex max-w-md flex-col items-center gap-3 text-center">
                <Sparkles class="size-8 text-muted-foreground" />
                <h3 class="text-lg font-semibold">Welcome to the Division ICT Inventory System</h3>
                <p class="text-sm text-muted-foreground">
                    Your account doesn't have access to any dashboard section yet. Once an
                    administrator assigns you a role with view permissions, summaries for
                    equipment, personnel, or ISP accounts will appear here.
                </p>
            </div>
        </div>

        <template v-else>
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                <Card v-if="equipment">
                    <CardHeader>
                        <CardTitle class="flex items-center gap-2">
                            <HardDrive class="size-4 text-muted-foreground" />
                            Equipment
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <dl class="grid grid-cols-2 gap-4">
                            <div class="space-y-1">
                                <dt class="text-sm text-muted-foreground">Total records</dt>
                                <dd class="text-lg font-semibold">{{ equipment.total }}</dd>
                            </div>
                            <div class="space-y-1">
                                <dt class="text-sm text-muted-foreground">Total acquisition cost</dt>
                                <dd class="text-lg font-semibold">
                                    {{ currencyFormatter.format(equipment.total_acquisition_cost) }}
                                </dd>
                            </div>
                            <div class="space-y-1">
                                <dt class="text-sm text-muted-foreground">Non-functional</dt>
                                <dd
                                    class="text-lg font-semibold"
                                    :class="equipment.non_functional_count > 0 ? 'text-amber-600 dark:text-amber-500' : ''"
                                >
                                    {{ equipment.non_functional_count }}
                                </dd>
                            </div>
                            <div class="space-y-1">
                                <dt class="text-sm text-muted-foreground">Warranty expiring soon</dt>
                                <dd
                                    class="text-lg font-semibold"
                                    :class="equipment.warranty_expiring_soon_count > 0 ? 'text-amber-600 dark:text-amber-500' : ''"
                                >
                                    {{ equipment.warranty_expiring_soon_count }}
                                </dd>
                            </div>
                        </dl>
                    </CardContent>
                </Card>

                <Card v-if="personnel">
                    <CardHeader>
                        <CardTitle class="flex items-center gap-2">
                            <Users class="size-4 text-muted-foreground" />
                            Personnel
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <dl class="grid grid-cols-2 gap-4">
                            <div class="space-y-1">
                                <dt class="text-sm text-muted-foreground">Active</dt>
                                <dd class="text-lg font-semibold">{{ personnel.active_count }}</dd>
                            </div>
                            <div class="space-y-1">
                                <dt class="text-sm text-muted-foreground">Inactive</dt>
                                <dd class="text-lg font-semibold">{{ personnel.inactive_count }}</dd>
                            </div>
                            <div class="space-y-1">
                                <dt class="text-sm text-muted-foreground">OIC assignments</dt>
                                <dd class="text-lg font-semibold">{{ personnel.oic_count }}</dd>
                            </div>
                        </dl>
                    </CardContent>
                </Card>

                <Card v-if="ispAccounts">
                    <CardHeader>
                        <CardTitle class="flex items-center gap-2">
                            <Wifi class="size-4 text-muted-foreground" />
                            ISP Accounts
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <dl class="grid grid-cols-2 gap-4">
                            <div class="space-y-1">
                                <dt class="text-sm text-muted-foreground">Total monthly cost</dt>
                                <dd class="text-lg font-semibold">
                                    {{ currencyFormatter.format(ispAccounts.total_monthly_cost) }}
                                </dd>
                            </div>
                            <div class="space-y-1">
                                <dt class="text-sm text-muted-foreground">Contracts expiring soon</dt>
                                <dd
                                    class="text-lg font-semibold"
                                    :class="ispAccounts.contracts_expiring_soon_count > 0 ? 'text-amber-600 dark:text-amber-500' : ''"
                                >
                                    {{ ispAccounts.contracts_expiring_soon_count }}
                                </dd>
                            </div>
                        </dl>
                    </CardContent>
                </Card>
            </div>

            <div v-if="equipment" class="grid gap-4 md:grid-cols-3">
                <Card>
                    <CardHeader>
                        <CardTitle class="text-sm font-medium text-muted-foreground">By condition</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <BreakdownList :items="equipment.by_condition" />
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader>
                        <CardTitle class="text-sm font-medium text-muted-foreground">By category</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <BreakdownList :items="equipment.by_category" />
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader>
                        <CardTitle class="text-sm font-medium text-muted-foreground">By disposition status</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <BreakdownList :items="equipment.by_disposition_status" />
                    </CardContent>
                </Card>
            </div>

            <Card v-if="recentActivity">
                <CardHeader>
                    <CardTitle class="flex items-center gap-2">
                        <Activity class="size-4 text-muted-foreground" />
                        Recent activity
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    <div class="overflow-x-auto rounded-lg border">
                        <table class="w-full text-left text-sm">
                            <thead class="border-b bg-muted/50 text-xs uppercase text-muted-foreground">
                                <tr>
                                    <th scope="col" class="px-4 py-3 font-medium">Equipment</th>
                                    <th scope="col" class="px-4 py-3 font-medium">Transaction</th>
                                    <th scope="col" class="px-4 py-3 font-medium">Accountable officer</th>
                                    <th scope="col" class="px-4 py-3 font-medium">End user</th>
                                    <th scope="col" class="px-4 py-3 font-medium">Recorded by</th>
                                    <th scope="col" class="px-4 py-3 font-medium">Date</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                <tr v-if="recentActivity.length === 0">
                                    <td colspan="6" class="px-4 py-8 text-center text-muted-foreground">
                                        No recent equipment activity.
                                    </td>
                                </tr>
                                <tr v-for="entry in recentActivity" :key="entry.id">
                                    <td class="px-4 py-3">
                                        <div v-if="entry.equipment" class="font-medium">
                                            {{ entry.equipment.property_no }}
                                        </div>
                                        <div v-if="entry.equipment" class="text-xs text-muted-foreground">
                                            {{ entry.equipment.item }}
                                        </div>
                                        <span v-else class="text-muted-foreground">—</span>
                                    </td>
                                    <td class="px-4 py-3">
                                        <Badge variant="outline">{{ entry.transaction_type }}</Badge>
                                    </td>
                                    <td class="px-4 py-3">{{ entry.accountable_officer?.full_name ?? '—' }}</td>
                                    <td class="px-4 py-3">{{ entry.end_user?.full_name ?? '—' }}</td>
                                    <td class="px-4 py-3">{{ entry.recorded_by?.name ?? '—' }}</td>
                                    <td class="px-4 py-3">{{ formatDateTime(entry.created_at) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </CardContent>
            </Card>
        </template>
    </div>
</template>
