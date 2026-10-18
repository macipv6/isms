<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import BcmConflictPanel from '@/components/bcm/BcmConflictPanel.vue';
import BiaTargetsForm from '@/components/bcm/BiaTargetsForm.vue';
import FormErrorList from '@/components/FormErrorList.vue';
import ProjectWorkNavigation from '@/components/ProjectWorkNavigation.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { bcmBase, type BiaPage, type BiaRevision } from '@/types/bcm';
const props = defineProps<
    Omit<BiaPage, 'revision'> & { revision: BiaRevision }
>();
const base = bcmBase(props.project.id);
const revisionBase = `${base}/bia/${props.revision.id}`;
const revise = useForm({ business_process_id: props.revision.process.id });
const pending = ref(false);
function successor(): void {
    if (props.capabilities.revise && !revise.processing)
        revise.post(`${revisionBase}/revise`);
}
function rebase(): void {
    if (
        props.capabilities.write &&
        props.revision.status === 'draft' &&
        !revise.processing &&
        !pending.value
    )
        revise.post(`${base}/bia`);
}
</script>
<template>
    <Head :title="revision.process.name + ' · BIA'" />
    <AppLayout>
        <Link :href="`${base}/bia`" class="text-sm text-cyan-300"
            >← BIA und Assetziele</Link
        >
        <h1 class="mt-5 text-3xl font-semibold">
            {{ revision.process.name }} · BIA v{{ revision.version }}
        </h1>
        <ProjectWorkNavigation
            :organization-id="project.organization_id"
            :project-id="project.id"
            active="bia"
        />
        <p class="mt-5">
            {{
                revision.status === 'approved'
                    ? 'Freigegeben · unveränderliche historische Revision'
                    : 'Entwurf · Freigabe ausstehend'
            }}<span v-if="revision.approved_at">
                · {{ revision.approved_at }}</span
            >
        </p>
        <p
            v-if="revision.status === 'approved' && !revision.freshness.current"
            class="mt-2 text-amber-200"
        >
            Erneute Prüfung erforderlich. Die historische Freigabe bleibt
            erhalten.
        </p>
        <p v-if="!capabilities.write" class="mt-2 text-amber-200">
            Schreibgeschützt{{
                !revision.process.is_active ? ' – Prozess inaktiv' : ''
            }}.
        </p>
        <div
            v-if="
                revision.status === 'draft' &&
                configuration &&
                revision.bia_configuration_version_id !== configuration.id
            "
            class="mt-4 rounded border border-amber-500/40 p-3"
        >
            <p>
                Dieser Entwurf verwendet ältere Zeitstufen:
                {{ revision.minutes.join(', ') }} Minuten. Aktuelle
                Konfiguration: {{ configuration.minutes.join(', ') }} Minuten.
            </p>
            <p class="mt-2 text-sm">
                Vor dem Neu-Zuordnen lokale Änderungen speichern. Nur exakt
                gleiche Zeitstufen bleiben erhalten; neue Zellen bleiben
                unbekannt.
            </p>
            <button
                v-if="capabilities.write"
                type="button"
                :disabled="revise.processing || pending"
                class="mt-3 text-cyan-300 disabled:opacity-50"
                @click="rebase"
            >
                Entwurf ausdrücklich auf aktuelle Zeitstufen neu zuordnen
            </button>
        </div>
        <FormErrorList :errors="revise.errors" :labels="{}" class="mt-3" />
        <button
            v-if="capabilities.revise"
            type="button"
            :disabled="revise.processing"
            class="mt-3 text-cyan-300 disabled:opacity-50"
            @click="successor"
        >
            Neue BIA-Revision anlegen
        </button>
        <BiaTargetsForm
            :key="`${revision.id}-${revision.status}-${revision.bia_configuration_version_id}`"
            :revision="revision"
            :base="revisionBase"
            :can-edit="!!capabilities.edit"
            :can-approve="!!capabilities.approve"
            @pending="pending = $event"
        />
        <BcmConflictPanel
            :key="lock_version"
            :conflicts="conflicts"
            :base="revisionBase"
            :lock-version="lock_version"
            :can-accept="!!capabilities.edit"
            :edits-pending="pending"
        />
        <section class="mt-6 rounded-xl border border-slate-800 p-4">
            <h2 class="text-lg font-semibold">
                {{
                    revision.status === 'approved'
                        ? 'Historisch verwendete Quellen'
                        : 'Aktuelle Abhängigkeitsquellen'
                }}
            </h2>
            <p v-if="!sources.data.length" class="mt-3">
                Keine Quellen auf dieser Seite.
            </p>
            <ul class="mt-3 space-y-3">
                <li v-for="(source, index) in sources.data" :key="index">
                    <p>
                        {{
                            source.target_type === 'asset' ? 'Asset' : 'Prozess'
                        }}
                        {{ source.target_name ?? source.target_id }} ·
                        {{
                            source.critical
                                ? 'Kritischer Pfad'
                                : 'Unterstützender Pfad'
                        }}
                    </p>
                    <Link
                        v-if="source.revision_id"
                        :href="
                            source.target_type === 'asset'
                                ? `${base}/assets/${source.target_id}/recovery/${source.revision_id}`
                                : `${base}/bia/${source.revision_id}`
                        "
                        class="text-cyan-300"
                        >Verwendete Zielrevision v{{ source.version }}</Link
                    >
                    <p v-else class="text-amber-200">
                        Ziel unbekannt – keine freigegebene Zielrevision
                    </p>
                </li>
            </ul>
            <nav class="mt-4 flex gap-4 text-sm" aria-label="Quellseiten">
                <Link v-if="sources.prev_page_url" :href="sources.prev_page_url"
                    >Zurück</Link
                ><span
                    >Seite {{ sources.current_page }} von
                    {{ sources.last_page }}</span
                ><Link
                    v-if="sources.next_page_url"
                    :href="sources.next_page_url"
                    >Weiter</Link
                >
            </nav>
        </section>
        <section class="mt-6 rounded-xl border border-slate-800 p-4">
            <h2 class="text-lg font-semibold">Revisionshistorie</h2>
            <ul class="mt-3 space-y-2">
                <li v-for="row in history.data" :key="row.id">
                    <Link :href="`${base}/bia/${row.id}`" class="text-cyan-300"
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
