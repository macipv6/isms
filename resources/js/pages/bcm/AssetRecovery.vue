<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AssetRecoveryPanel from '@/components/bcm/AssetRecoveryPanel.vue';
import ProjectWorkNavigation from '@/components/ProjectWorkNavigation.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { bcmBase, type AssetRecoveryPage } from '@/types/bcm';
const props = defineProps<AssetRecoveryPage>();
const base = bcmBase(props.project.id);
const recoveryBase = `${base}/assets/${props.asset.id}/recovery`;
</script>

<template>
    <Head :title="asset.name + ' · Wiederanlaufziele'" />
    <AppLayout>
        <Link :href="`${base}/bia`" class="text-sm text-cyan-300"
            >← BIA und Assetziele</Link
        >
        <h1 class="mt-5 text-3xl font-semibold">
            {{ asset.name }} · Wiederanlaufziele
        </h1>
        <ProjectWorkNavigation
            :organization-id="project.organization_id"
            :project-id="project.id"
            active="bia"
        />
        <p v-if="revision" class="mt-5">
            Version {{ revision.version }} ·
            {{
                revision.status === 'approved'
                    ? 'Freigegeben · unveränderliche historische Revision'
                    : 'Entwurf · Freigabe ausstehend'
            }}<span v-if="revision.approved_at">
                · {{ revision.approved_at }}</span
            >
        </p>
        <p
            v-if="
                revision?.status === 'approved' && !revision.freshness.current
            "
            class="mt-2 text-amber-200"
        >
            Erneute Prüfung erforderlich. Die historische Freigabe bleibt
            erhalten.
        </p>
        <p v-if="!capabilities.write" class="mt-2 text-amber-200">
            Schreibgeschützt{{ !asset.is_active ? ' – Asset inaktiv' : '' }}.
        </p>
        <AssetRecoveryPanel
            :key="`${revision?.id ?? asset.id}-${revision?.status}`"
            :revision="revision"
            :base="recoveryBase"
            :capabilities="capabilities"
        />
        <section class="mt-6 rounded-xl border border-slate-800 p-4">
            <h2 class="text-lg font-semibold">Revisionshistorie</h2>
            <ul class="mt-3 space-y-2">
                <li v-for="row in history.data" :key="row.id">
                    <Link
                        :href="`${recoveryBase}/${row.id}`"
                        class="text-cyan-300"
                        >Version {{ row.version }} ·
                        {{
                            row.status === 'approved'
                                ? 'Freigegeben'
                                : 'Entwurf'
                        }}</Link
                    ><span v-if="row.approved_at">
                        · {{ row.approved_at }}</span
                    >
                </li>
            </ul>
            <nav class="mt-4 flex gap-4 text-sm" aria-label="Historieseiten">
                <Link v-if="history.prev_page_url" :href="history.prev_page_url"
                    >Zurück</Link
                ><span
                    >Seite {{ history.current_page }} von
                    {{ history.last_page }}</span
                ><Link
                    v-if="history.next_page_url"
                    :href="history.next_page_url"
                    >Weiter</Link
                >
            </nav>
        </section>
    </AppLayout>
</template>
