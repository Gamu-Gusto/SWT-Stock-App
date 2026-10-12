<script setup lang="ts">
defineProps<{ size?: string | null; large?: boolean }>();
</script>

<template>
    <span v-if="size" class="size-chip" :class="large ? 'size-chip-lg' : 'text-[15px]'">{{ size }}</span>
    <span v-else class="text-faint">–</span>
</template>
