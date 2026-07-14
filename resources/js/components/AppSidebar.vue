<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Building2, Database, HardDrive, KeySquare, LayoutGrid, ShieldCheck, Signal, Users, Wifi } from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import NavMain from '@/components/NavMain.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { usePermissions } from '@/composables/usePermissions';
import { dashboard } from '@/routes';
import equipment from '@/routes/equipment';
import internetConnectivitySurvey from '@/routes/internet-connectivity-survey';
import ispAccounts from '@/routes/isp-accounts';
import personnel from '@/routes/personnel';
import referenceData from '@/routes/reference-data';
import roles from '@/routes/roles';
import stakeholderProfile from '@/routes/stakeholder-profile';
import users from '@/routes/users';
import type { NavItem } from '@/types';

const { can } = usePermissions();

const mainNavItems = computed<NavItem[]>(() => {
    const items: NavItem[] = [
        {
            title: 'Dashboard',
            href: dashboard(),
            icon: LayoutGrid,
        },
    ];

    if (can('personnel.view')) {
        items.push({
            title: 'Personnel',
            href: personnel.index(),
            icon: Users,
        });
    }

    if (can('equipment.view')) {
        items.push({
            title: 'Equipment',
            href: equipment.index(),
            icon: HardDrive,
        });
    }

    if (can('isp_accounts.view')) {
        items.push({
            title: 'ISP Accounts',
            href: ispAccounts.index(),
            icon: Wifi,
        });
    }

    if (can('stakeholder_profile.view')) {
        items.push({
            title: 'Stakeholder Profile',
            href: stakeholderProfile.edit(),
            icon: Building2,
        });
    }

    if (can('internet_connectivity.view')) {
        items.push({
            title: 'Internet Connectivity',
            href: internetConnectivitySurvey.edit(),
            icon: Signal,
        });
    }

    if (can('users.manage')) {
        items.push({
            title: 'Users',
            href: users.index(),
            icon: ShieldCheck,
        });
    }

    if (can('roles.manage')) {
        items.push({
            title: 'Roles',
            href: roles.index(),
            icon: KeySquare,
        });
    }

    // Successor to the old lookups.manage nav item (see
    // docs/architecture-decisions/lookup-normalization.md), removed in
    // the ADR's Step 4 cleanup alongside LookupController/lookups/Index.vue.
    if (can('reference-data.manage')) {
        items.push({
            title: 'Reference Data',
            href: referenceData.index(),
            icon: Database,
        });
    }

    return items;
});
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboard()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>
    </Sidebar>
    <slot />
</template>
