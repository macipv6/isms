<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import BcmExerciseForm from '@/components/bcm/BcmExerciseForm.vue';
import BcmExerciseResults from '@/components/bcm/BcmExerciseResults.vue';
import BcmSourceHistory from '@/components/bcm/BcmSourceHistory.vue';
import { useBcmFormState } from '@/components/bcm/biaState';
import FormErrorList from '@/components/FormErrorList.vue';
import ProjectWorkNavigation from '@/components/ProjectWorkNavigation.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { bcmBase, type ExercisePage, type Exercise } from '@/types/bcm';
const props = defineProps<
    Omit<ExercisePage, 'exercise'> & { exercise: Exercise }
>();
const base = bcmBase(props.project.id);
const exerciseBase = `${base}/exercises/${props.exercise.id}`;
const workBase = `/organizations/${props.project.organization_id}/projects/${props.project.id}`;
const draftPending = ref(false);
const resultsPending = ref(false);
const cancellation = useForm({
    lock_version: props.exercise.lock_version,
    reason: '',
});
const state = useBcmFormState(cancellation, () => props.exercise.lock_version);
const statuses: Record<string, string> = {
    draft: 'Entwurf',
    planned: 'Geplant',
    conducted: 'Durchgeführt',
    completed: 'Abgeschlossen',
    cancelled: 'Abgebrochen',
};
function cancel(): void {
    if (
        !props.capabilities.cancel ||
        !['draft', 'planned', 'conducted'].includes(props.exercise.status) ||
        draftPending.value ||
        resultsPending.value ||
        state.stale.value ||
        cancellation.processing
    )
        return;
    cancellation.post(`${exerciseBase}/cancel`, {
        preserveScroll: true,
        onHttpException: state.httpException,
    });
}
function reload(): void {
    router.reload({ onSuccess: state.refreshed });
}
</script>
<template>
    <Head title="BCM-Übung" /><AppLayout
        ><Link :href="`${base}/exercises`" class="text-cyan-300"
            >← Übungen</Link
        >
        <h1 class="mt-5 text-3xl font-semibold">
            {{ exercise.scenario || 'BCM-Übung' }}
        </h1>
        <ProjectWorkNavigation
            :organization-id="project.organization_id"
            :project-id="project.id"
            active="exercises"
        />
        <p class="mt-5">
            {{ statuses[exercise.status]
            }}<span v-if="exercise.completed_at">
                · {{ exercise.completed_at }}</span
            ><span v-if="exercise.cancelled_at">
                · {{ exercise.cancelled_at }}</span
            >
        </p>
        <p v-if="exercise.cancelled_reason">
            Abbruchgrund: {{ exercise.cancelled_reason }}
        </p>
        <Link
            :href="`${base}/plans/${exercise.plan_revision_id}`"
            class="text-cyan-300"
            >Ursprünglich zugeordnete Planrevision ansehen</Link
        >
        <p v-if="!exercise.freshness.current" class="mt-2 text-amber-200">
            Quellenlage erfordert erneute Prüfung. Der dokumentierte
            Übungsstatus bleibt erhalten.
        </p>
        <p v-if="!capabilities.write" class="mt-2 text-amber-200">
            Schreibgeschützt.
        </p>
        <p v-if="!exercise.lists_complete" class="mt-2 text-amber-200">
            Die gespeicherten Listen überschreiten die Bearbeitungsgrenze.
        </p>
        <BcmExerciseForm
            :key="`${exercise.id}-${exercise.status}`"
            :exercise="exercise"
            :base="exerciseBase"
            :work-base="workBase"
            :capabilities="capabilities"
            @pending="draftPending = $event"
        /><BcmExerciseResults
            v-if="exercise.status !== 'draft' && results"
            :key="`${exercise.id}-${exercise.status}`"
            :exercise="exercise"
            :results="results"
            :unrecorded-criteria="unrecorded_criteria"
            :base="exerciseBase"
            :work-base="workBase"
            :capabilities="capabilities"
            @pending="resultsPending = $event"
        />
        <form
            v-if="capabilities.cancel"
            class="mt-6 rounded border border-slate-700 p-4"
            @submit.prevent="cancel"
        >
            <label class="block"
                >Übung begründet abbrechen<textarea
                    v-model="cancellation.reason"
                    required
                    maxlength="10000"
                    class="mt-2 block w-full rounded border border-slate-600 bg-slate-950 p-2"
                /></label
            ><button
                :disabled="
                    draftPending ||
                    resultsPending ||
                    state.stale.value ||
                    cancellation.processing
                "
                class="mt-3 text-cyan-300"
            >
                Abbruch ausdrücklich dokumentieren</button
            ><FormErrorList :errors="cancellation.errors" :labels="{}" />
            <p
                v-if="state.stale.value"
                role="alert"
                class="mt-3 text-amber-200"
            >
                Bearbeitungsstand geändert; Begründung bleibt erhalten.
                <button type="button" @click="reload">
                    Aktuellen Stand ausdrücklich laden
                </button>
            </p>
        </form>
        <BcmSourceHistory
            :base="base"
            :sources="sources"
            :history="history"
            kind="exercises"
        />
    </AppLayout>
</template>
