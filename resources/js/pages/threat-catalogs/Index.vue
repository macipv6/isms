<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import CatalogVersionCard from '@/components/risk/CatalogVersionCard.vue';
import type { CatalogVersion } from '@/types/risk';
defineProps<{
    versions: CatalogVersion[];
    capabilities: { publish: boolean };
    versionPage: number;
    pagination: { next: string | null; previous: string | null };
}>();
</script>
<template>
    <Head title="Gefährdungskataloge" /><AppLayout
        ><h1 class="text-3xl font-semibold">Gefährdungskataloge</h1>
        <p class="my-3">
            Veröffentlichte Kataloge sind unveränderlich. Neue Inhalte werden in
            einem geklonten Entwurf gepflegt.
        </p>
        <p v-if="!capabilities.publish" class="text-amber-200">
            Lesemodus: Veröffentlichung und Entwurfsbearbeitung erfordern
            Administrationsrechte.
        </p>
        <CatalogVersionCard
            v-for="version in versions"
            :key="version.id"
            :version="version"
            :can-publish="capabilities.publish"
            :version-page="versionPage"
        />
        <nav aria-label="Katalogversionen" class="flex gap-4">
            <Link v-if="pagination.previous" :href="pagination.previous"
                >Vorherige Versionen</Link
            ><Link v-if="pagination.next" :href="pagination.next"
                >Weitere Versionen</Link
            >
        </nav></AppLayout
    >
</template>
<style scoped>
:deep(input:not([type='checkbox'])),
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
