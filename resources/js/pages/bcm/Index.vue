<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import BiaConfigurationPanel from '@/components/bcm/BiaConfigurationPanel.vue';
import ProjectWorkNavigation from '@/components/ProjectWorkNavigation.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { bcmBase, type BcmPage } from '@/types/bcm';
const props = defineProps<BcmPage>();
const base = bcmBase(props.project.id);
</script>

<template>
    <Head :title="project.name + ' · Business Continuity'" />
    <AppLayout>
        <h1 class="text-3xl font-semibold">
            {{ project.name }} · Business Continuity
        </h1>
        <p class="mt-2 text-slate-400">
            BIA, Wiederanlaufziele, Notfallpläne und Übungen für jedes Projekt.
        </p>
        <ProjectWorkNavigation
            :organization-id="project.organization_id"
            :project-id="project.id"
            active="bcm"
        />
        <p v-if="!capabilities.write" class="mt-6 text-amber-200">
            Dieses Projekt ist schreibgeschützt. Historische Freigaben bleiben
            einsehbar.
        </p>
        <div class="mt-6 grid gap-4 md:grid-cols-2">
            <section class="rounded-xl border border-slate-800 p-4">
                <h2 class="font-semibold">Fehlende Freigaben</h2>
                <p class="mt-2">
                    {{ overview.processes_missing_bia }} von
                    {{ overview.processes }} aktiven Prozessen ohne freigegebene
                    BIA
                </p>
                <p>
                    {{ overview.assets_missing_recovery }} von
                    {{ overview.assets }} aktiven Assets ohne freigegebene
                    Wiederanlaufziele
                </p>
                <p class="mt-2 text-sm text-slate-400">
                    {{ overview.bia_drafts }} BIA-Entwürfe · Freigabe ausstehend
                </p>
                <Link
                    :href="`${base}/bia`"
                    class="mt-3 inline-block text-cyan-300"
                    >BIA und Assetziele öffnen</Link
                >
            </section>
            <section class="rounded-xl border border-slate-800 p-4">
                <h2 class="font-semibold">Erneute Prüfung erforderlich</h2>
                <p class="mt-2">
                    {{ overview.bia_review_required }} freigegebene BIA ·
                    {{ overview.plan_review_required }} freigegebene Pläne ·
                    {{ overview.exercise_review_required }} Übungen
                </p>
                <p class="mt-2 text-sm text-slate-400">
                    Der historische Status bleibt erhalten; Änderungen an
                    Quellen werden getrennt angezeigt.
                </p>
            </section>
            <section class="rounded-xl border border-slate-800 p-4">
                <h2 class="font-semibold">Unbehandelte Konflikte</h2>
                <p class="mt-2">
                    {{ overview.conflicts }} Warnungen, davon
                    {{ overview.blocking_conflicts }} begründungspflichtig
                </p>
                <Link
                    :href="`${base}/bia`"
                    class="mt-3 inline-block text-cyan-300"
                    >Konfliktquellen prüfen</Link
                >
            </section>
            <section class="rounded-xl border border-slate-800 p-4">
                <h2 class="font-semibold">Plan- und Übungsstand</h2>
                <p class="mt-2">
                    {{ overview.plan_drafts }} Planentwürfe ·
                    {{ overview.plan_approved }} freigegebene Planrevisionen
                </p>
                <p class="mt-2">
                    Entwurf: {{ overview.exercises.draft ?? 0 }} · Geplant:
                    {{ overview.exercises.planned ?? 0 }} · Durchgeführt:
                    {{ overview.exercises.conducted ?? 0 }} · Abgeschlossen:
                    {{ overview.exercises.completed ?? 0 }} · Abgesagt:
                    {{ overview.exercises.cancelled ?? 0 }}
                </p>
                <div class="mt-3 flex gap-4">
                    <Link :href="`${base}/plans`" class="text-cyan-300"
                        >Notfallpläne</Link
                    ><Link :href="`${base}/exercises`" class="text-cyan-300"
                        >Übungen</Link
                    >
                </div>
            </section>
        </div>
        <BiaConfigurationPanel
            :configuration="configuration"
            :base="base"
            :can-write="capabilities.write"
            :lock-version="lock_version"
        />
        <section class="mt-6 rounded-xl border border-slate-800 p-4">
            <h2 class="font-semibold">Konfigurationshistorie</h2>
            <p v-if="!history.data.length" class="mt-3">
                Noch keine Konfiguration aktiviert.
            </p>
            <ul class="mt-3 space-y-2">
                <li v-for="row in history.data" :key="row.id">
                    Version {{ row.version }} · aktiviert {{ row.approved_at }}
                </li>
            </ul>
            <nav
                class="mt-4 flex gap-4 text-sm"
                aria-label="Konfigurationshistorie"
            >
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
