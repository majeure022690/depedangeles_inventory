<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { LogOut, Settings } from '@lucide/vue';
import {
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu';
import UserInfo from '@/components/UserInfo.vue';
import { edit } from '@/routes/profile';
import type { User } from '@/types';

type Props = {
    user: User;
};

defineProps<Props>();

/**
 * The confirmation Dialog can't live in here: this component is rendered as
 * DropdownMenuContent's slot content, and the dropdown unmounts that whole
 * subtree the instant an item is selected — destroying a nested Dialog along
 * with it regardless of its own open state. The parent owns the Dialog and
 * its open state instead; this just asks for it.
 */
const emit = defineEmits<{
    requestLogoutConfirm: [];
}>();
</script>

<template>
    <DropdownMenuLabel class="p-0 font-normal">
        <div class="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
            <UserInfo :user="user" :show-email="true" />
        </div>
    </DropdownMenuLabel>
    <DropdownMenuSeparator />
    <DropdownMenuGroup>
        <DropdownMenuItem :as-child="true">
            <Link class="block w-full cursor-pointer" :href="edit()" prefetch>
                <Settings class="mr-2 h-4 w-4" />
                Settings
            </Link>
        </DropdownMenuItem>
    </DropdownMenuGroup>
    <DropdownMenuSeparator />
    <DropdownMenuItem
        class="cursor-pointer"
        variant="destructive"
        data-test="logout-button"
        @select="emit('requestLogoutConfirm')"
    >
        <LogOut class="mr-2 h-4 w-4" />
        Log out
    </DropdownMenuItem>
</template>
