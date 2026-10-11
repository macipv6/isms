<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import MethodologyVersionCard from '@/components/risk/MethodologyVersionCard.vue';
import type { MethodologyVersion } from '@/types/risk';
defineProps<{
    versions: MethodologyVersion[];
    capabilities: { publish: boolean };
    pagination: { next: string | null; previous: string | null };
}>();
</script>
<template>
    <Head title="Risikomethodiken" /><AppLayout
        ><h1 class="text-3xl font-semibold">Risikomethodiken</h1>
        <p class="my-3">
            Veröffentlichte Versionen sind unveränderlich. Projekte verwenden
            ihre ausdrücklich fixierte Version.
        </p>
        <p v-if="!capabilities.publish" class="text-amber-200">
            Lesemodus: Veröffentlichung und Entwurfsbearbeitung erfordern
            Administrationsrechte.
        </p>
        <MethodologyVersionCard
            v-for="version in versions"
            :key="version.id"
            :version="version"
            :can-publish="capabilities.publish"
        />
        <nav aria-label="Methodikversionen" class="flex gap-4">
            <Link v-if="pagination.previous" :href="pagination.previous"
                >Vorherige Versionen</Link
            ><Link v-if="pagination.next" :href="pagination.next"
                >Weitere Versionen</Link
            >
        </nav></AppLayout
    >
</template>
<style scoped>
:deep(input),
:deep(select),
:deep(textarea) {
    display: block;
    width: 100%;
    max-width: 36rem;
    border: 1px solid #475569;
    border-radius: 0.375rem;
    background: #020617;
    padding: 0.5rem;
    margin-top: 0.25rem;
}
:deep(button) {
    border: 1px solid #475569;
    border-radius: 0.375rem;
    padding: 0.5rem 0.75rem;
    margin-top: 0.75rem;
}
:deep(button:disabled) {
    opacity: 0.5;
}
:deep(th),
:deep(td) {
    padding: 0.75rem 0.5rem;
    border-bottom: 1px solid #334155;
}
</style>
