<script setup lang="ts">
import { Monitor, Moon, Sun } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useAppearance } from '@/composables/useAppearance';
import type { Appearance } from '@/types/ui';

const { appearance, updateAppearance } = useAppearance();
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <Button
                variant="ghost"
                size="icon"
                class="group h-9 w-9 cursor-pointer"
                data-test="appearance-toggle-button"
            >
                <Sun class="size-5 opacity-80 group-hover:opacity-100 dark:hidden" />
                <Moon class="hidden size-5 opacity-80 group-hover:opacity-100 dark:block" />
                <span class="sr-only">Toggle theme</span>
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" class="w-40">
            <DropdownMenuRadioGroup
                :model-value="appearance"
                @update:model-value="(value) => updateAppearance(value as Appearance)"
            >
                <DropdownMenuRadioItem value="light" data-test="appearance-option-light">
                    <Sun class="size-4" />
                    Light
                </DropdownMenuRadioItem>
                <DropdownMenuRadioItem value="dark" data-test="appearance-option-dark">
                    <Moon class="size-4" />
                    Dark
                </DropdownMenuRadioItem>
                <DropdownMenuRadioItem value="system" data-test="appearance-option-system">
                    <Monitor class="size-4" />
                    System
                </DropdownMenuRadioItem>
            </DropdownMenuRadioGroup>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
