<script setup lang="ts">
import Index from './exercises/Index.vue';
import Show from './exercises/Show.vue';
import type { ExercisePage } from '@/types/bcm';
defineProps<ExercisePage>();
</script>

<template>
    <Show v-if="exercise" v-bind="$props" :exercise="exercise" /><Index
        v-else
        v-bind="$props"
    />
</template>
