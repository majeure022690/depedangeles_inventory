<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Lock, Plus, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
// Aliased (not `import roles from '@/routes/roles'`) because this page's
// own `roles` prop would otherwise shadow the route helper import.
import * as rolesRoutes from '@/routes/roles';
import type { RoleListItem } from '@/types/roles';

const props = defineProps<{
    roles: RoleListItem[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Roles', href: rolesRoutes.index() }],
    },
});

const pendingDelete = ref<RoleListItem | null>(null);

function isDeletable(row: RoleListItem): boolean {
    return !row.is_protected && row.user_count === 0;
}

function deleteBlockedReason(row: RoleListItem): string {
    if (row.is_protected) {
        return 'Protected roles cannot be deleted.';
    }

    return `Cannot delete — ${row.user_count} user${row.user_count === 1 ? '' : 's'} still hold this role.`;
}

function requestDelete(row: RoleListItem) {
    if (!isDeletable(row)) {
        return;
    }

    pendingDelete.value = row;
}

function confirmDelete() {
    if (!pendingDelete.value) {
        return;
    }

    router.delete(rolesRoutes.destroy.url(pendingDelete.value.id), {
        preserveScroll: true,
        onFinish: () => {
            pendingDelete.value = null;
        },
    });
}
</script>

<template>
    <Head title="Roles" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <Heading
                title="Roles"
                description="Define roles and the permissions each one grants."
            />
            <Link :href="rolesRoutes.create()">
                <Button data-test="add-role-button">
                    <Plus class="size-4" />
                    Create role
                </Button>
            </Link>
        </div>

        <div class="overflow-x-auto rounded-lg border">
            <table class="w-full text-left text-sm">
                <thead class="border-b bg-muted/50 text-xs uppercase text-muted-foreground">
                    <tr>
                        <th scope="col" class="px-4 py-3 font-medium">Role</th>
                        <th scope="col" class="px-4 py-3 font-medium">Description</th>
                        <th scope="col" class="px-4 py-3 font-medium">Users</th>
                        <th scope="col" class="px-4 py-3 font-medium">Permissions</th>
                        <th scope="col" class="px-4 py-3 font-medium">
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-if="props.roles.length === 0">
                        <td colspan="5" class="px-4 py-8 text-center text-muted-foreground">
                            No roles found.
                        </td>
                    </tr>
                    <tr v-for="row in props.roles" :key="row.id">
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-1.5 font-medium">
                                {{ row.label }}
                                <TooltipProvider v-if="row.is_protected">
                                    <Tooltip>
                                        <TooltipTrigger as-child>
                                            <span tabindex="0" class="inline-flex">
                                                <Lock
                                                    class="size-3.5 text-muted-foreground"
                                                    aria-label="Protected role"
                                                />
                                            </span>
                                        </TooltipTrigger>
                                        <TooltipContent>
                                            Protected role — name is fixed and it must always
                                            have zero permissions.
                                        </TooltipContent>
                                    </Tooltip>
                                </TooltipProvider>
                            </div>
                            <div class="font-mono text-xs text-muted-foreground">
                                {{ row.name }}
                            </div>
                        </td>
                        <td class="px-4 py-3 text-muted-foreground">
                            {{ row.description ?? '—' }}
                        </td>
                        <td class="px-4 py-3 tabular-nums">{{ row.user_count }}</td>
                        <td class="px-4 py-3 tabular-nums">{{ row.permission_count }}</td>
                        <td class="px-4 py-3">
                            <div class="flex items-center justify-end gap-2">
                                <Link :href="rolesRoutes.edit(row.id)">
                                    <Button variant="outline" size="sm">Edit</Button>
                                </Link>
                                <TooltipProvider v-if="!isDeletable(row)">
                                    <Tooltip>
                                        <TooltipTrigger as-child>
                                            <span tabindex="0" class="inline-block">
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    disabled
                                                    :aria-label="`Delete ${row.label}`"
                                                >
                                                    <Trash2 class="size-4 text-muted-foreground" />
                                                </Button>
                                            </span>
                                        </TooltipTrigger>
                                        <TooltipContent>
                                            {{ deleteBlockedReason(row) }}
                                        </TooltipContent>
                                    </Tooltip>
                                </TooltipProvider>
                                <Button
                                    v-else
                                    variant="ghost"
                                    size="sm"
                                    :aria-label="`Delete ${row.label}`"
                                    @click="requestDelete(row)"
                                >
                                    <Trash2 class="size-4 text-destructive" />
                                </Button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <Dialog :open="pendingDelete !== null" @update:open="(open) => !open && (pendingDelete = null)">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Delete role?</DialogTitle>
                <DialogDescription>
                    This will permanently delete the <strong>{{ pendingDelete?.label }}</strong>
                    role. This cannot be undone.
                </DialogDescription>
            </DialogHeader>
            <DialogFooter class="gap-2">
                <Button variant="secondary" @click="pendingDelete = null">Cancel</Button>
                <Button variant="destructive" @click="confirmDelete">Delete</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
