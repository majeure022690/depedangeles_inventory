<script setup lang="ts">
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import type { InternetConnectivitySurveyAggregates } from '@/types/internet-connectivity-survey';

defineProps<{
    aggregates: InternetConnectivitySurveyAggregates;
}>();

function currency(value: number): string {
    return new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(value);
}
</script>

<template>
    <Card>
        <CardHeader>
            <CardTitle>Protected summary</CardTitle>
            <CardDescription>
                Computed live from ISP Account records — not editable here. Update the source ISP accounts to change
                these figures.
            </CardDescription>
        </CardHeader>
        <CardContent>
            <dl class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                <div class="space-y-1">
                    <dt class="text-sm text-muted-foreground">Total ISPs</dt>
                    <dd class="text-lg font-semibold">{{ aggregates.total_isps }}</dd>
                </div>
                <div class="space-y-1">
                    <dt class="text-sm text-muted-foreground">Total cost/month</dt>
                    <dd class="text-lg font-semibold">{{ currency(aggregates.total_cost_per_month) }}</dd>
                </div>
                <div class="space-y-1">
                    <dt class="text-sm text-muted-foreground">Total amount spent</dt>
                    <dd class="text-lg font-semibold">{{ currency(aggregates.total_amount_spent) }}</dd>
                </div>
                <div class="space-y-1">
                    <dt class="text-sm text-muted-foreground">Total projected expenditure</dt>
                    <dd class="text-lg font-semibold">{{ currency(aggregates.total_projected_expenditure) }}</dd>
                </div>
                <div class="space-y-1">
                    <dt class="text-sm text-muted-foreground">Rooms covered (admin)</dt>
                    <dd class="text-lg font-semibold">{{ aggregates.rooms_covered_admin }}</dd>
                </div>
                <div class="space-y-1">
                    <dt class="text-sm text-muted-foreground">Rooms covered (classroom)</dt>
                    <dd class="text-lg font-semibold">{{ aggregates.rooms_covered_classroom }}</dd>
                </div>
                <div class="space-y-1">
                    <dt class="text-sm text-muted-foreground">Total access points</dt>
                    <dd class="text-lg font-semibold">{{ aggregates.total_access_points }}</dd>
                </div>
            </dl>
        </CardContent>
    </Card>
</template>
