<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed, reactive, watch } from 'vue';
import FormErrorList from '@/components/FormErrorList.vue';
import { absoluteDateTime, localDateTime } from './dateTime';
import BcmEvidencePicker from './BcmEvidencePicker.vue';
import BcmFindingActions from './BcmFindingActions.vue';
import { nullableInteger, useBcmFormState } from './biaState';
import type {
    BcmCapabilities,
    BcmPagination,
    Exercise,
    ExerciseCriterion,
    ExerciseOutcome,
    ExerciseResult,
} from '@/types/bcm';
const props = defineProps<{
    exercise: Exercise;
    results: BcmPagination<ExerciseResult>;
    unrecordedCriteria?: BcmPagination<ExerciseCriterion>;
    base: string;
    workBase: string;
    capabilities: BcmCapabilities;
}>();
const emit = defineEmits<{ pending: [value: boolean] }>();
const mutable = computed(
    () =>
        props.capabilities.record &&
        ['planned', 'conducted'].includes(props.exercise.status),
);
const outcomes: Record<ExerciseOutcome, string> = {
    passed: 'Erfüllt',
    partial: 'Teilweise erfüllt',
    failed: 'Nicht erfüllt',
    not_tested: 'Nicht getestet',
};
function rowData(row: ExerciseResult | ExerciseCriterion) {
    return 'criterion_id' in row
        ? {
              criterion_id: row.criterion_id,
              outcome: row.outcome,
              observations: row.observations ?? '',
              reason: row.reason ?? '',
              actual_minutes: row.actual_minutes,
          }
        : {
              criterion_id: row.id,
              outcome: null as ExerciseOutcome | null,
              observations: '',
              reason: '',
              actual_minutes: null as number | string | null,
          };
}
const form = mutable.value
    ? useForm({
          lock_version: props.exercise.lock_version,
          actual_at: localDateTime(props.exercise.actual_at),
          source_change_reason: '',
          acknowledge: false,
          evidence_ids: [...props.exercise.evidence_ids],
          results: [
              ...props.results.data
                  .filter((row) => row.capabilities.edit)
                  .map(rowData),
              ...(props.unrecordedCriteria?.data ?? []).map(rowData),
          ],
      })
    : null;
const action = useForm({ lock_version: props.exercise.lock_version });
const originals = new Map(
    (form?.results ?? []).map((row) => [row.criterion_id, { ...row }]),
);
const state = useBcmFormState(
    form ?? action,
    () => props.exercise.lock_version,
);
const proposals = reactive(new Map<string, ReturnType<typeof proposalForm>>());
const waivers = reactive(new Map<string, ReturnType<typeof waiverForm>>());
function proposalForm() {
    return useForm({ title: '', description: '', severity: 'medium' });
}
function waiverForm() {
    return useForm({ reason: '', lock_version: props.exercise.lock_version });
}
watch(
    () => [props.results.data, props.unrecordedCriteria?.data] as const,
    () => {
        for (const row of props.results.data) {
            if (!proposals.has(row.id)) proposals.set(row.id, proposalForm());
            if (!waivers.has(row.id)) waivers.set(row.id, waiverForm());
        }
        if (!form) return;
        const wasDirty = form.isDirty;
        for (const row of [
            ...props.results.data.filter((item) => item.capabilities.edit),
            ...(props.unrecordedCriteria?.data ?? []),
        ]) {
            const data = rowData(row);
            const existing = form.results.find(
                (item) => item.criterion_id === data.criterion_id,
            );
            if (!existing) {
                form.results.push(data);
                originals.set(data.criterion_id, { ...data });
            } else if (!wasDirty) {
                Object.assign(existing, data);
                originals.set(data.criterion_id, { ...data });
            }
        }
        if (!wasDirty) form.defaults();
    },
    { immediate: true },
);
const dirty = computed(() => !!form?.isDirty);
const processing = computed(
    () =>
        !!form?.processing ||
        action.processing ||
        [...proposals.values(), ...waivers.values()].some(
            (item) => item.processing,
        ),
);
watch(
    () => dirty.value || state.stale.value || processing.value,
    (value) => emit('pending', value),
    { immediate: true },
);
function editable(criterionId: string): boolean {
    return (
        !!mutable.value &&
        (!!props.results.data.find((row) => row.criterion_id === criterionId)
            ?.capabilities.edit ||
            !!props.unrecordedCriteria?.data.some(
                (row) => row.id === criterionId,
            ))
    );
}
function payload() {
    if (!form) return {};
    return {
        lock_version: form.lock_version,
        actual_at: absoluteDateTime(form.actual_at, props.exercise.actual_at),
        evidence_ids: [...form.evidence_ids],
        ...(form.acknowledge
            ? { source_change_reason: form.source_change_reason }
            : {}),
        results: form.results
            .filter((row) => {
                if (!editable(row.criterion_id)) return false;
                const saved = originals.get(row.criterion_id);
                if (!saved)
                    return (
                        row.outcome !== null ||
                        row.observations !== '' ||
                        row.reason !== '' ||
                        row.actual_minutes !== null
                    );
                return (
                    row.outcome !== saved.outcome ||
                    row.observations !== saved.observations ||
                    row.reason !== saved.reason ||
                    nullableInteger(row.actual_minutes) !== saved.actual_minutes
                );
            })
            .map((row) => ({
                criterion_id: row.criterion_id,
                outcome: row.outcome,
                observations: row.observations,
                reason: row.reason,
                actual_minutes: nullableInteger(row.actual_minutes),
            })),
    };
}
function record(): void {
    if (!form || !mutable.value || processing.value || state.stale.value)
        return;
    form.transform(payload).post(`${props.base}/record`, {
        preserveScroll: true,
        onHttpException: state.httpException,
        onSuccess: () => {
            state.refreshed();
            form.acknowledge = false;
            form.source_change_reason = '';
            for (const row of form.results)
                originals.set(row.criterion_id, { ...row });
            form.defaults();
        },
    });
}
function complete(): void {
    if (
        !props.capabilities.complete ||
        props.exercise.status !== 'conducted' ||
        dirty.value ||
        state.stale.value ||
        processing.value
    )
        return;
    action.lock_version = form?.lock_version ?? props.exercise.lock_version;
    action.post(`${props.base}/complete`, {
        preserveScroll: true,
        onHttpException: state.httpException,
    });
}
function needsDisposition(row: ExerciseResult): boolean {
    return (
        row.outcome !== null &&
        row.outcome !== 'passed' &&
        !row.finding_waived_at &&
        row.findings.total === 0
    );
}
function propose(id: string): void {
    const row = props.results.data.find((item) => item.id === id);
    const proposal = proposals.get(id);
    if (
        !mutable.value ||
        !row?.capabilities.propose_finding ||
        !proposal ||
        dirty.value ||
        processing.value ||
        state.stale.value
    )
        return;
    proposal.post(`${props.base}/results/${id}/finding`, {
        preserveScroll: true,
        onHttpException: state.httpException,
    });
}
function waive(id: string): void {
    const row = props.results.data.find((item) => item.id === id);
    const waiver = waivers.get(id);
    if (
        !mutable.value ||
        !row?.capabilities.waive_finding ||
        !waiver ||
        dirty.value ||
        processing.value ||
        state.stale.value
    )
        return;
    waiver.lock_version = form?.lock_version ?? props.exercise.lock_version;
    waiver.post(`${props.base}/results/${id}/waiver`, {
        preserveScroll: true,
        onHttpException: state.httpException,
        onSuccess: state.refreshed,
    });
}
function reload(): void {
    router.reload({
        onSuccess: () => {
            state.refreshed();
            action.lock_version = props.exercise.lock_version;
        },
    });
}
const visibleRows = computed(
    () => form?.results.filter((row) => editable(row.criterion_id)) ?? [],
);
</script>
<template>
    <section class="mt-6 rounded border border-slate-700 p-4">
        <h2 class="text-lg font-semibold">Durchführung und Ergebnisse</h2>
        <form v-if="form && mutable" @submit.prevent="record">
            <label class="mt-3 block"
                >Tatsächlicher Termin<input
                    v-model="form.actual_at"
                    type="datetime-local"
                    title="Ortszeit dieses Browsers"
            /></label>
            <div
                v-if="!exercise.freshness.current"
                class="mt-3 rounded border border-amber-500/40 p-3"
            >
                <p>
                    Die ursprüngliche Planrevision bleibt zugeordnet. Eine
                    geänderte Quellenlage muss für die dokumentierte
                    Durchführung begründet bestätigt werden; der Server
                    vergleicht den aktuellen Stand.
                </p>
                <p v-if="exercise.source_change_acknowledged_at">
                    Bisherige Bestätigung:
                    {{ exercise.source_change_acknowledged_at }} ·
                    {{ exercise.source_change_reason }}
                </p>
                <label
                    ><input v-model="form.acknowledge" type="checkbox" />
                    Aktuelle Quellenänderung ausdrücklich bestätigen</label
                ><label v-if="form.acknowledge"
                    >Neue Begründung<textarea
                        v-model="form.source_change_reason"
                        maxlength="10000"
                    />
                </label>
            </div>
            <article
                v-for="row in visibleRows"
                :key="row.criterion_id"
                class="mt-3 rounded border border-slate-700 p-3"
            >
                <p class="font-medium">
                    {{
                        exercise.criteria.find(
                            (item) => item.id === row.criterion_id,
                        )?.expected_result ??
                        unrecordedCriteria?.data.find(
                            (item) => item.id === row.criterion_id,
                        )?.expected_result ??
                        'Kriterium'
                    }}
                </p>
                <label
                    >Ergebnis<select v-model="row.outcome">
                        <option :value="null">Noch unbekannt</option>
                        <option
                            v-for="(label, outcome) in outcomes"
                            :key="outcome"
                            :value="outcome"
                        >
                            {{ label }}
                        </option>
                    </select></label
                ><label
                    >Beobachtungen<textarea
                        v-model="row.observations"
                        maxlength="10000"
                    /></label
                ><label
                    >Begründung<textarea
                        v-model="row.reason"
                        maxlength="10000"
                    /></label
                ><label
                    >Tatsächliche Dauer (Minuten)<input
                        v-model="row.actual_minutes"
                        type="number"
                        min="0"
                        step="1"
                /></label>
            </article>
            <BcmEvidencePicker
                v-model="form.evidence_ids"
                :base="workBase"
            /><FormErrorList :errors="form.errors" :labels="{}" /><button
                :disabled="processing || state.stale.value"
                class="mt-3"
            >
                Durchführung und bearbeitete Ergebnisse speichern
            </button>
            <p class="mt-2 text-sm text-slate-400">
                Andere Ergebnisse bleiben erhalten. Abschluss prüft alle
                Kriterien, Beobachtungen, Begründungen und den Umgang mit
                Abweichungen.
            </p>
        </form>
        <div v-else aria-label="Übungsergebnisse schreibgeschützt">
            <p class="mt-3">
                Tatsächlicher Termin: {{ exercise.actual_at ?? 'Unbekannt' }}
            </p>
            <p v-if="exercise.source_change_acknowledged_at">
                Quellenbestätigung:
                {{ exercise.source_change_acknowledged_at }} ·
                {{ exercise.source_change_reason }}
            </p>
            <BcmEvidencePicker
                :model-value="exercise.evidence_ids"
                :base="workBase"
                readonly
            />
        </div>
        <p v-if="state.stale.value" role="alert" class="mt-3 text-amber-200">
            Der Bearbeitungsstand wurde geändert. Ihre Eingaben bleiben
            erhalten.
            <button type="button" @click="reload">
                Aktuellen Stand ausdrücklich laden
            </button>
        </p>
        <p v-if="dirty" class="mt-3 text-amber-200">
            Änderungen vor Feststellungsvorschlag, Verzicht und Abschluss
            speichern.
        </p>
        <article
            v-for="row in results.data"
            :key="row.id"
            class="mt-4 rounded border border-slate-700 p-3"
        >
            <h3 class="font-semibold">
                {{
                    exercise.criteria.find(
                        (item) => item.id === row.criterion_id,
                    )?.expected_result ?? 'Gespeichertes Kriterium'
                }}
            </h3>
            <p>
                Gespeichertes Ergebnis:
                {{ row.outcome ? outcomes[row.outcome] : 'Unbekannt' }} ·
                {{ row.actual_minutes ?? 'Unbekannt' }} Minuten
            </p>
            <p class="whitespace-pre-wrap">{{ row.observations }}</p>
            <p class="whitespace-pre-wrap">{{ row.reason }}</p>
            <p v-if="row.findings.total" class="text-sm text-slate-400">
                Feststellungshistorie vorhanden: dieses Ergebnis bleibt
                unveränderlich.
            </p>
            <p v-if="needsDisposition(row)" class="mt-2 text-amber-200">
                Abweichung: Feststellungsvorschlag oder begründeter Verzicht
                fehlt.
            </p>
            <p v-if="row.finding_waived_at">
                Dokumentierter Verzicht: {{ row.finding_waiver_reason }} ·
                {{ row.finding_waived_at }}
            </p>
            <form
                v-if="
                    mutable &&
                    row.capabilities.propose_finding &&
                    proposals.get(row.id)
                "
                class="mt-3"
                @submit.prevent="propose(row.id)"
            >
                <h4>Feststellung ausdrücklich vorschlagen</h4>
                <label
                    >Titel<input
                        v-model="proposals.get(row.id)!.title"
                        required
                        maxlength="255" /></label
                ><label
                    >Beschreibung<textarea
                        v-model="proposals.get(row.id)!.description"
                        required
                        maxlength="10000"
                    /></label
                ><label
                    >Schweregrad<select
                        v-model="proposals.get(row.id)!.severity"
                    >
                        <option value="low">Niedrig</option>
                        <option value="medium">Mittel</option>
                        <option value="high">Hoch</option>
                        <option value="critical">Kritisch</option>
                    </select></label
                ><button :disabled="dirty || processing || state.stale.value">
                    Vorschlag anlegen</button
                ><FormErrorList
                    :errors="proposals.get(row.id)!.errors"
                    :labels="{}"
                />
            </form>
            <form
                v-if="
                    mutable &&
                    row.capabilities.waive_finding &&
                    waivers.get(row.id)
                "
                class="mt-3"
                @submit.prevent="waive(row.id)"
            >
                <label
                    >Begründung für Verzicht auf Feststellung<textarea
                        v-model="waivers.get(row.id)!.reason"
                        required
                        maxlength="10000"
                    /></label
                ><button :disabled="dirty || processing || state.stale.value">
                    Verzicht ausdrücklich dokumentieren</button
                ><FormErrorList
                    :errors="waivers.get(row.id)!.errors"
                    :labels="{}"
                />
            </form>
            <h4 class="mt-3 font-medium">
                Feststellungen und Maßnahmen · eigener Entscheidungsablauf
            </h4>
            <BcmFindingActions
                v-for="finding in row.findings.data"
                :key="finding.id"
                :finding="finding"
                :base="workBase"
            />
            <nav class="mt-2 flex gap-3" aria-label="Feststellungsseiten">
                <Link
                    v-if="row.findings.prev_page_url"
                    :href="row.findings.prev_page_url"
                    preserve-state
                    preserve-scroll
                    >Zurück</Link
                ><span
                    >{{ row.findings.current_page }} /
                    {{ row.findings.last_page }}</span
                ><Link
                    v-if="row.findings.next_page_url"
                    :href="row.findings.next_page_url"
                    preserve-state
                    preserve-scroll
                    >Weiter</Link
                >
            </nav>
        </article>
        <nav class="mt-4 flex gap-3" aria-label="Ergebnisseiten">
            <Link
                v-if="results.prev_page_url && !dirty"
                :href="results.prev_page_url"
                preserve-state
                preserve-scroll
                >Zurück</Link
            ><span
                >{{ results.current_page }} / {{ results.last_page }} ·
                {{ results.total }} Ergebnisse</span
            ><Link
                v-if="results.next_page_url && !dirty"
                :href="results.next_page_url"
                preserve-state
                preserve-scroll
                >Weiter</Link
            >
        </nav>
        <nav
            v-if="mutable && unrecordedCriteria"
            class="mt-3 flex gap-3"
            aria-label="Noch nicht erfasste Kriterien"
        >
            <Link
                v-if="unrecordedCriteria.prev_page_url && !dirty"
                :href="unrecordedCriteria.prev_page_url"
                preserve-state
                preserve-scroll
                >Zurück</Link
            ><span
                >{{ unrecordedCriteria.current_page }} /
                {{ unrecordedCriteria.last_page }} ·
                {{ unrecordedCriteria.total }} noch nicht erfasste
                Kriterien</span
            ><Link
                v-if="unrecordedCriteria.next_page_url && !dirty"
                :href="unrecordedCriteria.next_page_url"
                preserve-state
                preserve-scroll
                >Weiter</Link
            >
        </nav>
        <FormErrorList :errors="action.errors" :labels="{}" /><button
            v-if="capabilities.complete && exercise.status === 'conducted'"
            type="button"
            class="mt-4"
            :disabled="dirty || processing || state.stale.value"
            @click="complete"
        >
            Gespeicherte Übung abschließen
        </button>
        <p class="mt-2 text-sm text-slate-400">
            Feststellungsentscheidungen und Maßnahmen sind vom Übungsabschluss
            getrennt.
        </p>
    </section>
</template>
<style scoped>
input:not([type='checkbox']),
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
