import { usePage } from '@inertiajs/vue3';
import type { ComputedRef } from 'vue';
import { computed } from 'vue';

export type UsePermissionsReturn = {
    permissions: ComputedRef<string[]>;
    can: (permission: string) => boolean;
    canAny: (permissions: string[]) => boolean;
    canAll: (permissions: string[]) => boolean;
};

/**
 * Reads the current user's flat permission-string list from Inertia's
 * shared `auth.permissions` prop (see resources/js/types/auth.ts) and
 * exposes it as simple `can`/`canAny`/`canAll` checks for gating UI.
 *
 * This never re-derives authorization logic — it only reflects what the
 * backend already decided and shared. The server remains the source of
 * truth and re-checks every mutating request regardless of what this
 * composable renders.
 */
export function usePermissions(): UsePermissionsReturn {
    const page = usePage();
    const permissions = computed(() => page.props.auth?.permissions ?? []);

    function can(permission: string): boolean {
        return permissions.value.includes(permission);
    }

    function canAny(permissionsToCheck: string[]): boolean {
        return permissionsToCheck.some((permission) => can(permission));
    }

    function canAll(permissionsToCheck: string[]): boolean {
        return permissionsToCheck.every((permission) => can(permission));
    }

    return { permissions, can, canAny, canAll };
}
