<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { PaginationLink } from '@/types/pagination';

defineProps<{
    links: PaginationLink[];
}>();
</script>

<template>
    <nav
        v-if="links.length > 3"
        aria-label="Pagination"
        class="flex flex-wrap items-center justify-center gap-1"
    >
        <template v-for="(link, index) in links" :key="index">
            <span
                v-if="!link.url"
                class="px-3 py-1.5 text-sm text-muted-foreground"
                v-html="link.label"
            />
            <!-- eslint-disable vue/no-v-text-v-html-on-component -- link.label is Laravel's own paginator markup (e.g. "&laquo; Previous"), not user input; rendering it as text would show raw HTML entities instead of the arrows. -->
            <Link
                v-else
                :href="link.url"
                preserve-scroll
                preserve-state
                :aria-current="link.active ? 'page' : undefined"
                class="rounded-md px-3 py-1.5 text-sm transition-colors focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                :class="
                    link.active
                        ? 'bg-primary text-primary-foreground'
                        : 'text-foreground hover:bg-accent hover:text-accent-foreground'
                "
                v-html="link.label"
            />
            <!-- eslint-enable vue/no-v-text-v-html-on-component -->
        </template>
    </nav>
</template>
