<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import ContinuityPlanForm from '@/components/bcm/ContinuityPlanForm.vue';
import ProjectWorkNavigation from '@/components/ProjectWorkNavigation.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { bcmBase, type PlanPage } from '@/types/bcm';
const props = defineProps<PlanPage>();
const base = bcmBase(props.project.id);
const workBase = `/organizations/${props.project.organization_id}/projects/${props.project.id}`;
</script>
<template>
    <Head :title="project.name + ' · Notfallpläne'" /><AppLayout
        ><h1 class="text-3xl font-semibold">
            {{ project.name }} · Notfallpläne
        </h1>
        <ProjectWorkNavigation
            :organization-id="project.organization_id"
            :project-id="project.id"
            active="plans"
        />
        <p class="mt-5">
            {{ overview.plan_drafts }} Entwürfe ·
            {{ overview.plan_approved }} freigegebene Revisionen ·
            {{ overview.plan_review_required }} zur erneuten Prüfung
        </p>
        <p v-if="!capabilities.write" class="mt-3 text-amber-200">
            Schreibgeschützt. Frühere Pläne bleiben einsehbar.
        </p>
        <section class="mt-5 space-y-3">
            <article
                v-for="row in entries.data"
                :key="row.id"
                class="rounded border border-slate-700 p-4"
            >
                <Link :href="`${base}/plans/${row.id}`" class="text-cyan-300"
                    >{{ row.title || 'Plan ohne Titel' }} · v{{
                        row.version
                    }}</Link
                >
                <p>
                    {{ row.status === 'approved' ? 'Freigegeben' : 'Entwurf' }}
                </p>
                <p
                    v-if="row.status === 'approved' && !row.freshness.current"
                    class="text-amber-200"
                >
                    Erneute Prüfung erforderlich; historische Freigabe bleibt
                    erhalten.
                </p>
            </article>
            <p v-if="!entries.total">Noch keine Notfallpläne vorhanden.</p>
        </section>
        <nav class="mt-4 flex gap-3" aria-label="Planseiten">
            <Link v-if="entries.prev_page_url" :href="entries.prev_page_url"
                >Zurück</Link
            ><span>{{ entries.current_page }} / {{ entries.last_page }}</span
            ><Link v-if="entries.next_page_url" :href="entries.next_page_url"
                >Weiter</Link
            >
        </nav>
        <ContinuityPlanForm
            v-if="capabilities.write"
            :revision="null"
            :base="`${base}/plans`"
            :can-edit="capabilities.write"
            :process-options="process_options"
            :risk-options="risk_options"
            :evidence-base="workBase"
        />
    </AppLayout>
</template>
