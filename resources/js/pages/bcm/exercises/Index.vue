<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import BcmExerciseForm from '@/components/bcm/BcmExerciseForm.vue';
import ProjectWorkNavigation from '@/components/ProjectWorkNavigation.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { bcmBase, type ExercisePage } from '@/types/bcm';
const props = defineProps<ExercisePage>();
const base = bcmBase(props.project.id);
const workBase = `/organizations/${props.project.organization_id}/projects/${props.project.id}`;
const statuses: Record<string, string> = {
    draft: 'Entwurf',
    planned: 'Geplant',
    conducted: 'Durchgeführt',
    completed: 'Abgeschlossen',
    cancelled: 'Abgebrochen',
};
</script>
<template>
    <Head :title="project.name + ' · Übungen'" /><AppLayout
        ><h1 class="text-3xl font-semibold">{{ project.name }} · Übungen</h1>
        <ProjectWorkNavigation
            :organization-id="project.organization_id"
            :project-id="project.id"
            active="exercises" />
        <p v-if="!capabilities.write" class="mt-5 text-amber-200">
            Schreibgeschützt. Frühere Übungen bleiben einsehbar.
        </p>
        <section class="mt-5 space-y-3">
            <article
                v-for="row in entries.data"
                :key="row.id"
                class="rounded border border-slate-700 p-4"
            >
                <Link
                    :href="`${base}/exercises/${row.id}`"
                    class="text-cyan-300"
                    >{{ row.scenario || 'Übung ohne Szenario' }}</Link
                >
                <p>
                    {{ statuses[row.status] }} ·
                    {{ row.planned_at || 'Termin unbekannt' }}
                </p>
                <Link
                    :href="`${base}/plans/${row.plan_revision_id}`"
                    class="text-cyan-300"
                    >Zugeordnete Planrevision</Link
                >
                <p v-if="!row.freshness.current" class="text-amber-200">
                    Quellenlage erfordert Prüfung; dokumentierter Status bleibt
                    erhalten.
                </p>
            </article>
            <p v-if="!entries.total">Noch keine Übungen vorhanden.</p>
        </section>
        <nav class="mt-4 flex gap-3" aria-label="Übungsseiten">
            <Link v-if="entries.prev_page_url" :href="entries.prev_page_url"
                >Zurück</Link
            ><span>{{ entries.current_page }} / {{ entries.last_page }}</span
            ><Link v-if="entries.next_page_url" :href="entries.next_page_url"
                >Weiter</Link
            >
        </nav>
        <BcmExerciseForm
            v-if="capabilities.write"
            :exercise="null"
            :base="`${base}/exercises`"
            :work-base="workBase"
            :capabilities="capabilities"
            :plan-options="plan_options"
    /></AppLayout>
</template>
