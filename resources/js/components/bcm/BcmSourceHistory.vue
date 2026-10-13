<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type {
    BcmHistory,
    BcmPagination,
    BcmSource,
    ExerciseHistory,
} from '@/types/bcm';
const props = defineProps<{
    base: string;
    sources: BcmPagination<BcmSource>;
    history: BcmPagination<BcmHistory | ExerciseHistory>;
    kind: 'plans' | 'exercises';
}>();
function sourceUrl(source: BcmSource): string {
    return source.target_type === 'asset'
        ? `${props.base}/assets/${source.target_id}/recovery/${source.revision_id}`
        : `${props.base}/bia/${source.revision_id}`;
}
function historyUrl(row: { id: string }): string {
    return `${props.base}/${props.kind}/${row.id}`;
}
</script>
<template>
    <section class="mt-6 rounded border border-slate-700 p-4">
        <h2 class="text-lg font-semibold">Verwendete Quellrevisionen</h2>
        <p class="text-sm text-slate-400">
            Freigegebene Pläne und Übungen zeigen ihre ursprünglich verwendeten
            Revisionen.
        </p>
        <p v-if="!sources.data.length" class="mt-3">
            Keine Quellen auf dieser Seite.
        </p>
        <article
            v-for="(source, index) in sources.data"
            :key="index"
            class="mt-3"
        >
            <p>
                {{ source.target_type === 'asset' ? 'Asset' : 'Prozess' }}:
                {{ source.target_name ?? source.target_id }}
            </p>
            <Link
                v-if="source.revision_id"
                :href="sourceUrl(source)"
                class="text-cyan-300"
                >Verwendete Revision v{{ source.version }}</Link
            >
            <p v-else class="text-amber-200">Freigegebene Quelle fehlt.</p>
            <p
                v-if="source.freshness && !source.freshness.current"
                class="text-amber-200"
            >
                Quelle erfordert Prüfung.
            </p>
        </article>
        <nav class="mt-3 flex gap-3" aria-label="Quellseiten">
            <Link v-if="sources.prev_page_url" :href="sources.prev_page_url"
                >Zurück</Link
            ><span>{{ sources.current_page }} / {{ sources.last_page }}</span
            ><Link v-if="sources.next_page_url" :href="sources.next_page_url"
                >Weiter</Link
            >
        </nav>
    </section>
    <section class="mt-6 rounded border border-slate-700 p-4">
        <h2 class="text-lg font-semibold">Historie</h2>
        <p v-for="row in history.data" :key="row.id" class="mt-2">
            <Link :href="historyUrl(row)" class="text-cyan-300"
                >{{
                    'version' in row
                        ? `Version ${row.version}`
                        : row.planned_at || 'Übung ohne Termin'
                }}
                · {{ row.status }}</Link
            >
        </p>
        <nav class="mt-3 flex gap-3" aria-label="Historienseiten">
            <Link v-if="history.prev_page_url" :href="history.prev_page_url"
                >Zurück</Link
            ><span>{{ history.current_page }} / {{ history.last_page }}</span
            ><Link v-if="history.next_page_url" :href="history.next_page_url"
                >Weiter</Link
            >
        </nav>
    </section>
</template>
