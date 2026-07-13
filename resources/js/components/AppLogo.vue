<script setup lang="ts">
import AppLogoIcon from '@/components/AppLogoIcon.vue';

/**
 * The sidebar rail is too narrow for the full app name (page.props.name,
 * "DepEd Division ICT Inventory System") — it just truncates to
 * "DepEd Division ICT Inv…" with no useful information gained over the
 * short form. Use the same DICTIS acronym already chosen for the browser
 * tab title (see resources/js/app.ts's VITE_APP_NAME) instead of the
 * dynamic full name here specifically; AuthSplitLayout.vue's login-page
 * panel has room for the full descriptive name and still uses it.
 */
const shortName = 'DICTIS';
</script>

<template>
    <div class="flex aspect-square size-8 items-center justify-center">
        <AppLogoIcon class="size-8" />
    </div>
    <div class="ml-1 grid flex-1 text-left text-sm">
        <span class="mb-0.5 truncate leading-tight font-semibold">{{
            shortName
        }}</span>
    </div>
</template>
