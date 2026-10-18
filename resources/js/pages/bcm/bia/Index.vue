<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import BiaConfigurationPanel from '@/components/bcm/BiaConfigurationPanel.vue';
import FormErrorList from '@/components/FormErrorList.vue';
import ProjectWorkNavigation from '@/components/ProjectWorkNavigation.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { bcmBase, type BiaPage, type BiaProcess } from '@/types/bcm';
const props = defineProps<BiaPage>();
const base = bcmBase(props.project.id);
const draft = useForm({ business_process_id: '' });
function create(process: BiaProcess): void {
    if (
        !props.capabilities.write ||
        !props.configuration ||
        !process.is_active ||
        draft.processing
    )
        return;
    draft.business_process_id = process.id;
    draft.post(`${base}/bia`);
}
</script>
<template>
    <Head :title="project.name + ' · BIA'" />
    <AppLayout>
        <Link :href="base" class="text-sm text-cyan-300">← BCM-Übersicht</Link>
        <h1 class="mt-5 text-3xl font-semibold">
            {{ project.name }} · Business Impact Analysis
        </h1>
        <ProjectWorkNavigation
            :organization-id="project.organization_id"
            :project-id="project.id"
            active="bia"
        />
        <p v-if="!capabilities.write" class="mt-6 text-amber-200">
            Dieses Projekt ist schreibgeschützt. Historische Bewertungen bleiben
            einsehbar.
        </p>
        <BiaConfigurationPanel
            v-if="!configuration"
            :configuration="configuration"
            :base="base"
            :can-write="capabilities.write"
            :lock-version="lock_version"
        />
        <FormErrorList
            :errors="draft.errors"
            :labels="{
                business_process_id: 'Prozess',
                configuration: 'Zeitstufen',
            }"
            class="mt-4"
        />
        <section class="mt-6 rounded-xl border border-slate-800 p-4">
            <h2 class="text-lg font-semibold">Prozessbewertungen</h2>
            <p v-if="!entries.data.length" class="mt-3">
                Noch keine Prozesse vorhanden.
            </p>
            <article
                v-for="process in entries.data"
                :key="process.id"
                class="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-slate-800 pt-3"
            >
                <div>
                    <h3 class="font-semibold">
                        {{ process.name }}
                        <span
                            v-if="!process.is_active"
                            class="text-sm text-slate-400"
                            >(inaktiv)</span
                        >
                    </h3>
                    <p class="mt-1 text-sm text-slate-400">
                        {{
                            process.draft
                                ? 'Entwurf – Freigabe ausstehend'
                                : !process.approved
                                  ? 'Freigegebene BIA fehlt'
                                  : 'BIA freigegeben'
                        }}
                    </p>
                    <p
                        v-if="
                            process.approved &&
                            !process.approved.freshness.current
                        "
                        class="text-sm text-amber-200"
                    >
                        Freigegebene BIA: erneute Prüfung erforderlich
                    </p>
                </div>
                <div class="flex gap-4 text-sm">
                    <Link
                        v-if="process.approved"
                        :href="`${base}/bia/${process.approved.id}`"
                        class="text-cyan-300"
                        >Freigabe v{{ process.approved.version }}</Link
                    ><Link
                        v-if="process.draft"
                        :href="`${base}/bia/${process.draft.id}`"
                        class="text-cyan-300"
                        >Entwurf v{{ process.draft.version }}</Link
                    ><button
                        v-else-if="
                            capabilities.write &&
                            process.is_active &&
                            configuration
                        "
                        type="button"
                        :disabled="draft.processing"
                        class="text-cyan-300 disabled:opacity-50"
                        @click="create(process)"
                    >
                        {{
                            process.approved
                                ? 'Folgerevision anlegen'
                                : 'Entwurf anlegen'
                        }}
                    </button>
                </div>
            </article>
            <nav class="mt-4 flex gap-4 text-sm" aria-label="Prozessseiten">
                <Link v-if="entries.prev_page_url" :href="entries.prev_page_url"
                    >Zurück</Link
                ><span
                    >Seite {{ entries.current_page }} von
                    {{ entries.last_page }}</span
                ><Link
                    v-if="entries.next_page_url"
                    :href="entries.next_page_url"
                    >Weiter</Link
                >
            </nav>
        </section>
        <section
            v-if="assets"
            class="mt-6 rounded-xl border border-slate-800 p-4"
        >
            <h2 class="text-lg font-semibold">Asset-Wiederanlaufziele</h2>
            <p v-if="!assets.data.length" class="mt-3">
                Noch keine Assets vorhanden.
            </p>
            <ul class="mt-3 space-y-2">
                <li v-for="asset in assets.data" :key="asset.id">
                    <Link
                        :href="`${base}/assets/${asset.id}/recovery`"
                        class="text-cyan-300"
                        >{{ asset.name }}</Link
                    ><span
                        v-if="!asset.is_active"
                        class="ml-2 text-sm text-slate-400"
                        >inaktiv</span
                    >
                </li>
            </ul>
            <nav class="mt-4 flex gap-4 text-sm" aria-label="Assetseiten">
                <Link v-if="assets.prev_page_url" :href="assets.prev_page_url"
                    >Zurück</Link
                ><span
                    >Seite {{ assets.current_page }} von
                    {{ assets.last_page }}</span
                ><Link v-if="assets.next_page_url" :href="assets.next_page_url"
                    >Weiter</Link
                >
            </nav>
        </section>
    </AppLayout>
</template>
