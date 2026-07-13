<script setup lang="ts">
import { computed } from 'vue';
import type { DashboardCount } from '@/types/dashboard';

const props = defineProps<{
    items: DashboardCount[];
}>();

// Every bar is measured relative to the largest count in this particular
// breakdown so the widest bar always fills the row — a single neutral hue
// (bg-primary) is enough since these are ranked magnitudes of one metric,
// not distinct series that need to stay visually separable from each other.
const maxCount = computed(() => Math.max(1, ...props.items.map((item) => item.count)));
</script>

<template>
    <ul v-if="items.length > 0" class="space-y-3">
        <li v-for="item in items" :key="item.value">
            <div class="mb-1 flex items-center justify-between gap-3 text-sm">
                <span class="truncate">{{ item.value }}</span>
                <span class="tabular-nums text-muted-foreground">{{ item.count }}</span>
            </div>
            <div class="h-1.5 w-full overflow-hidden rounded-full bg-muted" role="presentation">
                <div
                    class="h-full rounded-full bg-primary"
                    :style="{ width: `${(item.count / maxCount) * 100}%` }"
                />
            </div>
        </li>
    </ul>
    <p v-else class="text-sm text-muted-foreground">No data yet.</p>
</template>
