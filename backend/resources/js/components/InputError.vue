<script setup lang="ts">
defineProps<{
    message?: string;
}>();
</script>

<template>
    <p v-show="message" role="alert" aria-live="polite" class="text-sm text-red-600 dark:text-red-500">
        {{ message }}
    </p>
</template>
