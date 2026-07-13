<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { toUrl } from '@/lib/utils';
import type { NavItem } from '@/types';

defineProps<{
    items: NavItem[];
}>();

const { isCurrentUrl } = useCurrentUrl();
</script>

<template>
    <SidebarGroup class="px-2 py-0">
        <SidebarGroupLabel>Platform</SidebarGroupLabel>
        <SidebarMenu>
            <SidebarMenuItem v-for="item in items" :key="item.title">
                <SidebarMenuButton
                    as-child
                    :is-active="isCurrentUrl(item.href)"
                    :tooltip="item.title"
                >
                    <!--
                        Plain anchor (not <Link>) for the already-active
                        item: clicking it would otherwise still fire a full
                        Inertia visit to the URL you're already on — a
                        pointless round-trip and progress-bar flash. This
                        branch is deliberately NOT wired through Inertia's
                        router at all (a conditional inside Link's own
                        click handler would run too late to reliably cancel
                        it), so there is no navigation to cancel in the
                        first place.
                    -->
                    <a
                        v-if="isCurrentUrl(item.href)"
                        :href="toUrl(item.href)"
                        aria-current="page"
                        @click.prevent
                    >
                        <component :is="item.icon" />
                        <span>{{ item.title }}</span>
                    </a>
                    <Link v-else :href="item.href">
                        <component :is="item.icon" />
                        <span>{{ item.title }}</span>
                    </Link>
                </SidebarMenuButton>
            </SidebarMenuItem>
        </SidebarMenu>
    </SidebarGroup>
</template>
