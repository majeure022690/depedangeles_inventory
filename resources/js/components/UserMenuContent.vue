<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { LogOut, Settings } from '@lucide/vue';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu';
import UserInfo from '@/components/UserInfo.vue';
import { logout } from '@/routes';
import { edit } from '@/routes/profile';
import type { User } from '@/types';

type Props = {
    user: User;
};

defineProps<Props>();

const showLogoutConfirm = ref(false);

/**
 * Deferred to the next tick: opening the Dialog synchronously inside the
 * DropdownMenuItem's @select handler races the dropdown's own close/focus-
 * return cycle, so the Dialog's outside-click detector catches the tail end
 * of that same click and immediately dismisses itself.
 */
function openLogoutConfirm() {
    requestAnimationFrame(() => {
        showLogoutConfirm.value = true;
    });
}

const handleLogout = () => {
    router.flushAll();
};
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
        @select="openLogoutConfirm"
    >
        <LogOut class="mr-2 h-4 w-4" />
        Log out
    </DropdownMenuItem>

    <Dialog v-model:open="showLogoutConfirm">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Log out?</DialogTitle>
                <DialogDescription>
                    You'll need to sign in again to access your account.
                </DialogDescription>
            </DialogHeader>
            <DialogFooter class="gap-2">
                <DialogClose as-child>
                    <Button variant="secondary">Cancel</Button>
                </DialogClose>
                <Button variant="destructive" as-child>
                    <Link
                        :href="logout()"
                        @click="handleLogout"
                        data-test="confirm-logout-button"
                    >
                        Log out
                    </Link>
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
