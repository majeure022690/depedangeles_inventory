<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import Pagination from '@/components/Pagination.vue';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { useTableFilters } from '@/composables/useTableFilters';
import internetConnectivitySurveys from '@/routes/internet-connectivity-surveys';
import type { InternetConnectivitySurveyFilters, InternetConnectivitySurveyOfficeListItem } from '@/types/internet-connectivity-survey';
import type { Paginated } from '@/types/pagination';

const props = defineProps<{
    offices: Paginated<InternetConnectivitySurveyOfficeListItem>;
    filters: InternetConnectivitySurveyFilters;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Internet Connectivity Survey', href: internetConnectivitySurveys.index() }],
    },
});

const { filters, update } = useTableFilters(internetConnectivitySurveys.index.url(), {
    search: props.filters.search ?? '',
}, {
    debounceKeys: ['search'],
});
</script>

<template>
    <Head title="Internet Connectivity Survey" />

    <div class="flex flex-col gap-6 p-4">
        <Heading
            title="Internet Connectivity Survey"
            description="Every school and division-level office's internet connectivity survey — one per office, filled in by that office's own users."
        />

        <div class="sm:max-w-sm">
            <Input
                :model-value="filters.search"
                type="search"
                placeholder="Search by office name..."
                aria-label="Search offices"
                @update:model-value="(value) => update('search', String(value))"
            />
        </div>

        <div class="overflow-x-auto rounded-lg border">
            <table class="w-full text-left text-sm">
                <thead class="border-b bg-muted/50 text-xs uppercase text-muted-foreground">
                    <tr>
                        <th scope="col" class="px-4 py-3 font-medium">Office</th>
                        <th scope="col" class="px-4 py-3 font-medium">Type</th>
                        <th scope="col" class="px-4 py-3 font-medium">School ID</th>
                        <th scope="col" class="px-4 py-3 font-medium">Status</th>
                        <th scope="col" class="px-4 py-3 font-medium">Last updated</th>
                        <th scope="col" class="px-4 py-3 font-medium">
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-if="props.offices.data.length === 0">
                        <td colspan="6" class="px-4 py-8 text-center text-muted-foreground">
                            No offices found.
                        </td>
                    </tr>
                    <tr v-for="row in props.offices.data" :key="row.id">
                        <td class="px-4 py-3 font-medium">{{ row.office_name }}</td>
                        <td class="px-4 py-3">{{ row.office_type ?? '—' }}</td>
                        <td class="px-4 py-3">{{ row.school_id ?? '—' }}</td>
                        <td class="px-4 py-3">
                            <Badge :variant="row.has_survey ? 'secondary' : 'outline'">
                                {{ row.has_survey ? 'Answered' : 'Not started' }}
                            </Badge>
                        </td>
                        <td class="px-4 py-3 text-xs text-muted-foreground">
                            {{ row.updated_at ? new Date(row.updated_at).toLocaleString() : '—' }}
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center justify-end">
                                <Link
                                    :href="internetConnectivitySurveys.edit.url(row.id)"
                                    class="text-sm font-medium text-foreground underline decoration-neutral-300 underline-offset-4 hover:decoration-current"
                                >
                                    {{ row.has_survey ? 'Edit' : 'Start' }}
                                </Link>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Pagination :links="props.offices.links" />
    </div>
</template>
