<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { usePermissions } from '@/composables/usePermissions';
import { toUrl } from '@/lib/utils';
import { edit as editAppearance } from '@/routes/appearance';
import { edit as editProfile } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import { edit as editStakeholderProfile } from '@/routes/stakeholder-profile';
import type { NavItem } from '@/types';

withDefaults(
    defineProps<{
        /**
         * Stakeholder Profile is a wide, multi-column form — this widens the
         * content column for that tab instead of the narrow single-field-
         * per-line width the other (simple) settings tabs use.
         */
        wide?: boolean;
    }>(),
    {
        wide: false,
    },
);

const { can } = usePermissions();

const sidebarNavItems = computed<NavItem[]>(() => {
    const items: NavItem[] = [
        {
            title: 'Profile',
            href: editProfile(),
        },
        {
            title: 'Security',
            href: editSecurity(),
        },
        {
            title: 'Appearance',
            href: editAppearance(),
        },
    ];

    if (can('stakeholder_profile.view')) {
        items.push({
            title: 'Stakeholder Profile',
            href: editStakeholderProfile(),
        });
    }

    return items;
});

const { isCurrentOrParentUrl } = useCurrentUrl();
</script>

<template>
    <div class="px-4 py-6">
        <Heading
            title="Settings"
            description="Manage your profile and account settings"
        />

        <div class="flex flex-col lg:flex-row lg:space-x-12">
            <aside class="w-full max-w-xl lg:w-48">
                <nav
                    class="flex flex-col space-y-1 space-x-0"
                    aria-label="Settings"
                >
                    <Button
                        v-for="item in sidebarNavItems"
                        :key="toUrl(item.href)"
                        variant="ghost"
                        :class="[
                            'w-full justify-start',
                            { 'bg-muted': isCurrentOrParentUrl(item.href) },
                        ]"
                        as-child
                    >
                        <Link :href="item.href">
                            <component :is="item.icon" class="h-4 w-4" />
                            {{ item.title }}
                        </Link>
                    </Button>
                </nav>
            </aside>

            <Separator class="my-6 lg:hidden" />

            <div class="flex-1" :class="wide ? '' : 'md:max-w-2xl'">
                <section :class="wide ? 'space-y-12' : 'max-w-xl space-y-12'">
                    <slot />
                </section>
            </div>
        </div>
    </div>
</template>
