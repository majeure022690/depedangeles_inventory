<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ChevronRight, Database, Library } from '@lucide/vue';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import referenceData from '@/routes/reference-data';
import type { ReferenceDataTableSummary } from '@/types/reference-data';

const props = defineProps<{
    tables: ReferenceDataTableSummary[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Reference Data', href: referenceData.index() }],
    },
});

const tier1Tables = computed(() => props.tables.filter((table) => table.tier === 1));
const tier2Tables = computed(() => props.tables.filter((table) => table.tier === 2));
</script>

<template>
    <Head title="Reference Data" />

    <div class="flex flex-col gap-8 p-4">
        <Heading
            title="Reference Data"
            description="Manage the dedicated and grouped reference tables used throughout equipment, personnel, and ISP account forms."
        />

        <section class="space-y-3">
            <div class="flex items-center gap-2">
                <Database class="size-4 text-muted-foreground" />
                <h3 class="text-sm font-medium text-muted-foreground">Dedicated tables</h3>
            </div>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <Link
                    v-for="table in tier1Tables"
                    :key="table.key"
                    :href="referenceData.show.url(table.key)"
                    class="block rounded-xl focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                >
                    <Card class="h-full transition-colors hover:border-primary/50">
                        <CardHeader>
                            <CardTitle class="flex items-center justify-between gap-2 text-base">
                                <span>{{ table.label }}</span>
                                <ChevronRight class="size-4 shrink-0 text-muted-foreground" />
                            </CardTitle>
                        </CardHeader>
                        <CardContent class="flex items-center justify-between">
                            <Badge variant="secondary">Tier 1</Badge>
                            <span class="text-sm text-muted-foreground">
                                {{ table.row_count }} {{ table.row_count === 1 ? 'row' : 'rows' }}
                            </span>
                        </CardContent>
                    </Card>
                </Link>
            </div>
        </section>

        <section class="space-y-3">
            <div class="flex items-center gap-2">
                <Library class="size-4 text-muted-foreground" />
                <h3 class="text-sm font-medium text-muted-foreground">Grouped libraries</h3>
            </div>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <Link
                    v-for="table in tier2Tables"
                    :key="table.key"
                    :href="referenceData.show.url(table.key)"
                    class="block rounded-xl focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                >
                    <Card class="h-full transition-colors hover:border-primary/50">
                        <CardHeader>
                            <CardTitle class="flex items-center justify-between gap-2 text-base">
                                <span>{{ table.label }}</span>
                                <ChevronRight class="size-4 shrink-0 text-muted-foreground" />
                            </CardTitle>
                        </CardHeader>
                        <CardContent class="flex items-center justify-between">
                            <Badge variant="outline">Tier 2</Badge>
                            <span class="text-sm text-muted-foreground">
                                {{ table.row_count }} {{ table.row_count === 1 ? 'row' : 'rows' }}
                            </span>
                        </CardContent>
                    </Card>
                </Link>
            </div>
        </section>
    </div>
</template>
