<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import FormErrorList from '@/components/FormErrorList.vue';
import { absoluteDateTime, localDateTime } from './dateTime';
import BcmEvidencePicker from './BcmEvidencePicker.vue';
import { nullableInteger, useBcmFormState } from './biaState';
import type {
    BcmCapabilities,
    BcmPagination,
    Exercise,
    PlanOption,
} from '@/types/bcm';
const props = defineProps<{
    exercise: Exercise | null;
    base: string;
    workBase: string;
    capabilities: BcmCapabilities;
    planOptions?: BcmPagination<PlanOption>;
}>();
const emit = defineEmits<{ pending: [value: boolean] }>();
const editable = computed(() =>
    props.exercise
        ? props.capabilities.edit && props.exercise.status === 'draft'
        : props.capabilities.write,
);
const form = editable.value
    ? useForm({
          lock_version: props.exercise?.lock_version ?? 0,
          plan_revision_id: props.exercise?.plan_revision_id ?? '',
          scenario: props.exercise?.scenario ?? '',
          exercise_type: props.exercise?.exercise_type ?? 'tabletop',
          planned_at: localDateTime(props.exercise?.planned_at),
          leader_name: props.exercise?.leader_name ?? '',
          participants: props.exercise?.participants ?? '',
          criteria:
              props.exercise?.criteria.map((row) => ({
                  expected_result: row.expected_result,
                  expected_minutes: row.expected_minutes,
              })) ?? [],
          evidence_ids: [...(props.exercise?.evidence_ids ?? [])],
      })
    : null;
const action = useForm({ lock_version: props.exercise?.lock_version ?? 0 });
const state = useBcmFormState(
    form ?? action,
    () => props.exercise?.lock_version ?? 0,
);
const dirty = computed(() => !!form?.isDirty);
watch(
    () =>
        dirty.value ||
        state.stale.value ||
        !!form?.processing ||
        action.processing,
    (value) => emit('pending', value),
    { immediate: true },
);
function payload() {
    if (!form) return {};
    return {
        lock_version: form.lock_version,
        ...(!props.exercise ? { plan_revision_id: form.plan_revision_id } : {}),
        scenario: form.scenario,
        exercise_type: form.exercise_type,
        planned_at: absoluteDateTime(
            form.planned_at,
            props.exercise?.planned_at,
        ),
        leader_name: form.leader_name,
        participants: form.participants,
        criteria: form.criteria.map((row) => ({
            expected_result: row.expected_result,
            expected_minutes: nullableInteger(row.expected_minutes),
        })),
        evidence_ids: [...form.evidence_ids],
    };
}
function save(): void {
    if (
        !form ||
        !editable.value ||
        state.stale.value ||
        form.processing ||
        action.processing
    )
        return;
    const options = {
        preserveScroll: true,
        onHttpException: state.httpException,
        onSuccess: () => {
            state.refreshed();
            form.defaults();
        },
    };
    if (props.exercise) form.transform(payload).put(props.base, options);
    else form.transform(payload).post(props.base, options);
}
function plan(): void {
    if (
        !props.capabilities.plan ||
        props.exercise?.status !== 'draft' ||
        dirty.value ||
        state.stale.value ||
        form?.processing ||
        action.processing
    )
        return;
    action.lock_version = form?.lock_version ?? props.exercise.lock_version;
    action.post(`${props.base}/plan`, {
        preserveScroll: true,
        onHttpException: state.httpException,
    });
}
function reload(): void {
    router.reload({
        onSuccess: () => {
            state.refreshed();
            action.lock_version = props.exercise?.lock_version ?? 0;
        },
    });
}
</script>
<template>
    <section class="mt-6 rounded border border-slate-700 p-4">
        <form v-if="form && editable" @submit.prevent="save">
            <h2 class="text-lg font-semibold">
                {{
                    exercise
                        ? 'Übungsentwurf bearbeiten'
                        : 'Übungsentwurf anlegen'
                }}
            </h2>
            <div v-if="!exercise && planOptions" class="mt-3">
                <label
                    >Freigegebene Planrevision<select
                        v-model="form.plan_revision_id"
                        required
                    >
                        <option value="">Bitte auswählen</option>
                        <option
                            v-if="
                                form.plan_revision_id &&
                                !planOptions.data.some(
                                    (row) => row.id === form!.plan_revision_id,
                                )
                            "
                            :value="form.plan_revision_id"
                        >
                            Bereits ausgewählte Revision
                        </option>
                        <option
                            v-for="row in planOptions.data"
                            :key="row.id"
                            :value="row.id"
                        >
                            {{ row.title || 'Plan' }} · v{{ row.version }}
                            {{
                                row.freshness.current
                                    ? ''
                                    : '· Prüfung erforderlich'
                            }}
                        </option>
                    </select></label
                >
                <nav class="mt-2 flex gap-3" aria-label="Plan-Auswahlseiten">
                    <Link
                        v-if="planOptions.prev_page_url"
                        :href="planOptions.prev_page_url"
                        preserve-state
                        preserve-scroll
                        >Zurück</Link
                    ><span
                        >{{ planOptions.current_page }} /
                        {{ planOptions.last_page }}</span
                    ><Link
                        v-if="planOptions.next_page_url"
                        :href="planOptions.next_page_url"
                        preserve-state
                        preserve-scroll
                        >Weiter</Link
                    >
                </nav>
                <p class="text-sm text-slate-400">
                    Eine historische freigegebene Revision kann zugeordnet
                    werden. Die Planung prüft ihre Quellen erneut.
                </p>
            </div>
            <label class="mt-3 block"
                >Szenario<textarea
                    v-model="form.scenario"
                    maxlength="10000"
                /></label
            ><label
                >Übungstyp<select v-model="form.exercise_type">
                    <option value="tabletop">Tabletop</option>
                    <option value="recovery_test">Wiederanlauftest</option>
                </select></label
            ><label
                >Geplanter Termin<input
                    v-model="form.planned_at"
                    type="datetime-local"
                    title="Ortszeit dieses Browsers" /></label
            ><label
                >Übungsleitung<input
                    v-model="form.leader_name"
                    maxlength="160" /></label
            ><label
                >Teilnehmende<textarea
                    v-model="form.participants"
                    maxlength="10000"
                />
            </label>
            <h3 class="mt-4 font-semibold">Erfolgskriterien</h3>
            <article
                v-for="(row, index) in form.criteria"
                :key="index"
                class="mt-3 rounded border border-slate-700 p-3"
            >
                <p>Kriterium {{ index + 1 }}</p>
                <label
                    >Erwartetes Ergebnis<textarea
                        v-model="row.expected_result"
                        maxlength="10000"
                    /></label
                ><label
                    >Erwartete Dauer (Minuten)<input
                        v-model="row.expected_minutes"
                        type="number"
                        min="0"
                        step="1" /></label
                ><button type="button" @click="form.criteria.splice(index, 1)">
                    Entfernen
                </button>
            </article>
            <button
                type="button"
                class="mt-3"
                :disabled="form.criteria.length >= 200"
                @click="
                    form.criteria.push({
                        expected_result: '',
                        expected_minutes: null,
                    })
                "
            >
                Kriterium hinzufügen</button
            ><BcmEvidencePicker
                v-model="form.evidence_ids"
                :base="workBase"
            /><FormErrorList :errors="form.errors" :labels="{}" /><button
                class="mt-4"
                :disabled="
                    state.stale.value || form.processing || action.processing
                "
            >
                Übungsentwurf speichern
            </button>
        </form>
        <div v-else-if="exercise" aria-label="Übungsplanung schreibgeschützt">
            <h2 class="text-lg font-semibold">Gespeicherte Übungsplanung</h2>
            <p class="mt-3 whitespace-pre-wrap">{{ exercise.scenario }}</p>
            <p>
                {{ exercise.exercise_type }} ·
                {{ exercise.planned_at ?? 'Termin unbekannt' }}
            </p>
            <p>Leitung: {{ exercise.leader_name || 'Unbekannt' }}</p>
            <p class="whitespace-pre-wrap">
                Teilnehmende: {{ exercise.participants || 'Unbekannt' }}
            </p>
            <ol class="mt-3 list-inside list-decimal">
                <li v-for="row in exercise.criteria" :key="row.id">
                    {{ row.expected_result || 'Unbekannt' }} ·
                    {{ row.expected_minutes ?? 'Unbekannt' }} Minuten
                </li>
            </ol>
        </div>
        <p v-if="state.stale.value" role="alert" class="mt-3 text-amber-200">
            Der Bearbeitungsstand wurde geändert. Ihre Eingaben bleiben
            erhalten.
            <button type="button" @click="reload">
                Aktuellen Stand ausdrücklich laden
            </button>
        </p>
        <p v-if="dirty && capabilities.plan" class="mt-3 text-amber-200">
            Änderungen vor der Planung speichern.
        </p>
        <FormErrorList :errors="action.errors" :labels="{}" /><button
            v-if="capabilities.plan && exercise?.status === 'draft'"
            type="button"
            class="mt-3"
            :disabled="
                dirty ||
                state.stale.value ||
                form?.processing ||
                action.processing
            "
            @click="plan"
        >
            Gespeicherte Übung planen
        </button>
    </section>
</template>
<style scoped>
input,
textarea,
select {
    display: block;
    width: 100%;
    margin: 0.25rem 0 0.5rem;
    padding: 0.5rem;
    border: 1px solid #475569;
    border-radius: 0.25rem;
    background: #020617;
}
button {
    color: #67e8f9;
}
button:disabled {
    opacity: 0.4;
}
</style>
