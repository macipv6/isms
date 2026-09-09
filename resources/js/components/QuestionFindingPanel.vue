<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import FindingMeasurePanel from '@/components/FindingMeasurePanel.vue';
import type { QuestionFinding, QuestionWorkItems } from '@/types/assessment';
import type { FindingSeverity, FindingStatus } from '@/types/work-items';

const props = defineProps<{ workItems: QuestionWorkItems }>();

const proposalForm = useForm<{
    title: string;
    description: string;
    severity: FindingSeverity;
}>({ title: '', description: '', severity: 'medium' });

function createEditForm(finding: QuestionFinding) {
    return useForm({
        title: finding.title,
        description: finding.description,
        severity: finding.severity,
    });
}

function createDecisionForm() {
    return useForm<{
        status: Extract<FindingStatus, 'accepted' | 'rejected'> | '';
        decision_note: string;
    }>({ status: '', decision_note: '' });
}

const editForms = Object.fromEntries(
    props.workItems.findings.map((finding) => [
        finding.id,
        createEditForm(finding),
    ]),
) as Record<string, ReturnType<typeof createEditForm>>;
const decisionForms = Object.fromEntries(
    props.workItems.findings.map((finding) => [
        finding.id,
        createDecisionForm(),
    ]),
) as Record<string, ReturnType<typeof createDecisionForm>>;
const closeForms = Object.fromEntries(
    props.workItems.findings.map((finding) => [finding.id, useForm({})]),
) as Record<string, ReturnType<typeof useForm>>;
const linkForms = Object.fromEntries(
    props.workItems.findings.map((finding) => [finding.id, useForm({})]),
) as Record<string, ReturnType<typeof useForm>>;
const selectedEvidenceUrls = ref<Record<string, string>>({});

const statusLabels: Record<FindingStatus, string> = {
    proposed: 'Vorgeschlagen',
    accepted: 'Akzeptiert',
    rejected: 'Verworfen',
    closed: 'Geschlossen',
};
const severityLabels: Record<FindingSeverity, string> = {
    low: 'Niedrig',
    medium: 'Mittel',
    high: 'Hoch',
    critical: 'Kritisch',
};

function propose(): void {
    proposalForm.post(props.workItems.propose_finding_url, {
        preserveScroll: true,
        onSuccess: () => proposalForm.reset(),
    });
}

function updateFinding(finding: QuestionFinding): void {
    editForms[finding.id]?.put(finding.update_url, { preserveScroll: true });
}

function decide(
    finding: QuestionFinding,
    status: Extract<FindingStatus, 'accepted' | 'rejected'>,
): void {
    const form = decisionForms[finding.id];

    if (!form) return;
    form.status = status;
    form.patch(finding.decision_url, { preserveScroll: true });
}

function closeFinding(finding: QuestionFinding): void {
    closeForms[finding.id]?.patch(finding.close_url, { preserveScroll: true });
}

function linkEvidence(finding: QuestionFinding): void {
    const url = selectedEvidenceUrls.value[finding.id];
    const form = linkForms[finding.id];

    if (!url || !form) return;
    form.post(url, {
        preserveScroll: true,
        onSuccess: () => {
            selectedEvidenceUrls.value[finding.id] = '';
        },
    });
}
</script>

<template>
    <section class="rounded-xl border border-slate-800 bg-slate-950/40 p-4">
        <h4 class="font-semibold text-slate-100">Feststellungen</h4>

        <form
            v-if="workItems.can_propose_finding"
            class="mt-4 grid gap-3 md:grid-cols-2"
            @submit.prevent="propose"
        >
            <input
                v-model="proposalForm.title"
                required
                maxlength="255"
                placeholder="Titel der Feststellung"
                class="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2"
            />
            <select
                v-model="proposalForm.severity"
                class="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2"
            >
                <option
                    v-for="(label, value) in severityLabels"
                    :key="value"
                    :value="value"
                >
                    {{ label }}
                </option>
            </select>
            <textarea
                v-model="proposalForm.description"
                required
                maxlength="10000"
                rows="3"
                placeholder="Beschreibung der Abweichung"
                class="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 md:col-span-2"
            />
            <button
                type="submit"
                :disabled="proposalForm.processing"
                class="rounded-lg bg-amber-400 px-4 py-2 font-semibold text-slate-950 disabled:opacity-50"
            >
                Feststellung vorschlagen
            </button>
            <p
                v-for="(message, field) in proposalForm.errors"
                :key="field"
                class="text-sm text-red-300 md:col-span-2"
            >
                {{ message }}
            </p>
            <p
                v-if="proposalForm.recentlySuccessful"
                class="text-sm text-emerald-300 md:col-span-2"
            >
                Feststellung vorgeschlagen.
            </p>
        </form>

        <div v-if="workItems.findings.length" class="mt-4 space-y-4">
            <article
                v-for="finding in workItems.findings"
                :key="finding.id"
                class="rounded-lg border border-slate-800 p-4"
            >
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h5 class="font-medium">{{ finding.title }}</h5>
                        <p class="mt-1 text-sm text-slate-300">
                            {{ finding.description }}
                        </p>
                    </div>
                    <span class="text-xs text-slate-400">
                        {{ severityLabels[finding.severity] }} ·
                        {{ statusLabels[finding.status] }}
                    </span>
                </div>

                <form
                    v-if="finding.can_edit && editForms[finding.id]"
                    class="mt-3 grid gap-2 md:grid-cols-2"
                    @submit.prevent="updateFinding(finding)"
                >
                    <input
                        v-model="editForms[finding.id].title"
                        required
                        maxlength="255"
                        class="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm"
                    />
                    <select
                        v-model="editForms[finding.id].severity"
                        class="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm"
                    >
                        <option
                            v-for="(label, value) in severityLabels"
                            :key="value"
                            :value="value"
                        >
                            {{ label }}
                        </option>
                    </select>
                    <textarea
                        v-model="editForms[finding.id].description"
                        required
                        maxlength="10000"
                        rows="2"
                        class="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm md:col-span-2"
                    />
                    <button
                        type="submit"
                        :disabled="editForms[finding.id].processing"
                        class="rounded-lg border border-slate-600 px-3 py-2 text-sm"
                    >
                        Feststellung speichern
                    </button>
                    <p
                        v-for="(message, field) in editForms[finding.id].errors"
                        :key="field"
                        class="text-sm text-red-300 md:col-span-2"
                    >
                        {{ message }}
                    </p>
                    <p
                        v-if="editForms[finding.id].recentlySuccessful"
                        class="text-sm text-emerald-300 md:col-span-2"
                    >
                        Feststellung gespeichert.
                    </p>
                </form>

                <form
                    v-if="finding.can_decide && decisionForms[finding.id]"
                    class="mt-3 space-y-2"
                    @submit.prevent
                >
                    <input
                        v-model="decisionForms[finding.id].decision_note"
                        maxlength="10000"
                        placeholder="Begründung bei Verwerfung"
                        class="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm"
                    />
                    <div class="flex flex-wrap gap-2">
                        <button
                            type="button"
                            :disabled="decisionForms[finding.id].processing"
                            class="rounded-lg bg-emerald-500 px-3 py-2 text-sm font-medium text-slate-950"
                            @click="decide(finding, 'accepted')"
                        >
                            Akzeptieren
                        </button>
                        <button
                            type="button"
                            :disabled="
                                decisionForms[finding.id].processing ||
                                !decisionForms[finding.id].decision_note.trim()
                            "
                            class="rounded-lg bg-red-500 px-3 py-2 text-sm font-medium text-white disabled:opacity-50"
                            @click="decide(finding, 'rejected')"
                        >
                            Verwerfen
                        </button>
                    </div>
                    <p
                        v-for="(message, field) in decisionForms[finding.id]
                            .errors"
                        :key="field"
                        class="text-sm text-red-300"
                    >
                        {{ message }}
                    </p>
                    <p
                        v-if="decisionForms[finding.id].recentlySuccessful"
                        class="text-sm text-emerald-300"
                    >
                        Entscheidung gespeichert.
                    </p>
                </form>

                <form
                    v-if="
                        finding.can_link_evidence &&
                        finding.available_evidence.length &&
                        linkForms[finding.id]
                    "
                    class="mt-3 flex flex-wrap items-end gap-2"
                    @submit.prevent="linkEvidence(finding)"
                >
                    <label class="min-w-64 flex-1 text-sm text-slate-300">
                        Nachweis verknüpfen
                        <select
                            v-model="selectedEvidenceUrls[finding.id]"
                            required
                            class="mt-1 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2"
                        >
                            <option value="">Bitte auswählen</option>
                            <option
                                v-for="evidence in finding.available_evidence"
                                :key="evidence.id"
                                :value="evidence.link_url"
                            >
                                {{ evidence.original_name }}
                            </option>
                        </select>
                    </label>
                    <button
                        type="submit"
                        :disabled="linkForms[finding.id].processing"
                        class="rounded-lg border border-cyan-600 px-3 py-2 text-sm text-cyan-200"
                    >
                        Verknüpfen
                    </button>
                    <p
                        v-for="(message, field) in linkForms[finding.id].errors"
                        :key="field"
                        class="w-full text-sm text-red-300"
                    >
                        {{ message }}
                    </p>
                    <p
                        v-if="linkForms[finding.id].recentlySuccessful"
                        class="w-full text-sm text-emerald-300"
                    >
                        Nachweis verknüpft.
                    </p>
                </form>

                <div
                    v-if="finding.evidence.length"
                    class="mt-3 flex flex-wrap gap-2"
                >
                    <a
                        v-for="evidence in finding.evidence"
                        :key="evidence.id"
                        :href="evidence.download_url"
                        class="rounded-lg border border-slate-700 px-3 py-2 text-sm text-cyan-200"
                    >
                        {{ evidence.original_name }} herunterladen
                    </a>
                </div>

                <FindingMeasurePanel
                    v-if="
                        finding.status === 'accepted' ||
                        finding.status === 'closed' ||
                        finding.measures.total > 0
                    "
                    :finding="finding"
                />

                <form
                    v-if="finding.can_close && closeForms[finding.id]"
                    class="mt-3"
                    @submit.prevent="closeFinding(finding)"
                >
                    <button
                        type="submit"
                        :disabled="closeForms[finding.id].processing"
                        class="rounded-lg border border-emerald-600 px-3 py-2 text-sm text-emerald-200"
                    >
                        Feststellung schließen
                    </button>
                    <p
                        v-for="(message, field) in closeForms[finding.id]
                            .errors"
                        :key="field"
                        class="mt-2 text-sm text-red-300"
                    >
                        {{ message }}
                    </p>
                    <p
                        v-if="closeForms[finding.id].recentlySuccessful"
                        class="mt-2 text-sm text-emerald-300"
                    >
                        Feststellung geschlossen.
                    </p>
                </form>
            </article>
        </div>
        <p v-else class="mt-3 text-sm text-slate-500">
            Für diese Frage gibt es noch keine Feststellung.
        </p>
    </section>
</template>
