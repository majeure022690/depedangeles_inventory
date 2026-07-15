<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Building2, Database, HardDrive, History, KeySquare, LayoutGrid, ShieldCheck, Signal, Users, Wifi } from '@lucide/vue';
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
import auditLog from '@/routes/audit-log';
import equipment from '@/routes/equipment';
import internetConnectivitySurveys from '@/routes/internet-connectivity-surveys';
import ispAccounts from '@/routes/isp-accounts';
import personnel from '@/routes/personnel';
import referenceData from '@/routes/reference-data';
import roles from '@/routes/roles';
import stakeholderProfiles from '@/routes/stakeholder-profiles';
import users from '@/routes/users';
import type { NavItem } from '@/types';

const { can } = usePermissions();
const page = usePage();

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

    // Cross-office admins (view_all) get the all-offices list; a regular
    // office-scoped user (view, own office_id) goes straight to their own
    // office's profile — there's nothing else for them to browse. Hidden
    // entirely for a view-holder with no office_id (nothing to show).
    if (can('stakeholder_profile.view_all')) {
        items.push({
            title: 'Stakeholder Profile',
            href: stakeholderProfiles.index(),
            icon: Building2,
        });
    } else if (can('stakeholder_profile.view') && typeof page.props.auth.user.office_id === 'number') {
        items.push({
            title: 'Stakeholder Profile',
            href: stakeholderProfiles.edit(page.props.auth.user.office_id),
            icon: Building2,
        });
    }

    // Cross-office admins (view_all) get the all-offices list; a regular
    // office-scoped user (view, own office_id) goes straight to their own
    // office's survey — there's nothing else for them to browse. Hidden
    // entirely for a view-holder with no office_id (nothing to show).
    if (can('internet_connectivity.view_all')) {
        items.push({
            title: 'Internet Connectivity',
            href: internetConnectivitySurveys.index(),
            icon: Signal,
        });
    } else if (can('internet_connectivity.view') && typeof page.props.auth.user.office_id === 'number') {
        items.push({
            title: 'Internet Connectivity',
            href: internetConnectivitySurveys.edit(page.props.auth.user.office_id),
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

    if (can('audit_log.view')) {
        items.push({
            title: 'Audit Log',
            href: auditLog.index(),
            icon: History,
        });
    }

    // Successor to the old lookups.manage nav item (see
    // docs/architecture-decisions/lookup-normalization.md), removed in
    // the ADR's Step 4 cleanup alongside LookupController/lookups/Index.vue.
    if (can('reference-data.manage')) {
        items.push({
            title: 'Libraries',
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
