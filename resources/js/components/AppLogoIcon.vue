<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
};

defineProps<Props>();
</script>

<template>
    <img
        src="/sdoac.png"
        alt="SDO Angeles City"
        :class="className"
        class="object-contain"
        v-bind="$attrs"
    />
</template>
