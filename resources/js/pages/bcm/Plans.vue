<script setup lang="ts">
import Index from './plans/Index.vue';
import Show from './plans/Show.vue';
import type { PlanPage } from '@/types/bcm';
defineProps<PlanPage>();
</script>

<template>
    <Show v-if="revision" v-bind="$props" :revision="revision" /><Index
        v-else
        v-bind="$props"
    />
</template>
