<script setup lang="ts">
import BiaIndex from './bia/Index.vue';
import BiaShow from './bia/Show.vue';
import type { BiaPage } from '@/types/bcm';
defineProps<BiaPage>();
</script>

<template>
    <BiaShow
        v-if="revision"
        :key="revision.id"
        v-bind="$props"
        :revision="revision"
    />
    <BiaIndex v-else v-bind="$props" />
</template>
