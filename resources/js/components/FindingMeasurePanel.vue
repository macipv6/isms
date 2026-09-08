<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import type { QuestionFinding, QuestionMeasure } from '@/types/assessment';
import type { MeasurePriority, MeasureStatus } from '@/types/work-items';

const props = defineProps<{ finding: QuestionFinding }>();

const createForm = useForm<{
    title: string;
    description: string;
    priority: MeasurePriority;
    responsible_name: string;
    responsible_email: string;
    due_date: string;
}>({
    title: '',
    description: '',
    priority: 'medium',
    responsible_name: '',
    responsible_email: '',
    due_date: '',
});

function createEditForm(measure: QuestionMeasure) {
    return useForm({
        title: measure.title,
        description: measure.description,
        priority: measure.priority,
        responsible_name: measure.responsible_name,
        responsible_email: measure.responsible_email ?? '',
        due_date: measure.due_date,
    });
}

function createStatusForm() {
    return useForm<{ status: MeasureStatus | ''; reason: string }>({
        status: '',
        reason: '',
    });
}

const editForms = Object.fromEntries(
    props.finding.measures.items.map((measure) => [
        measure.id,
        createEditForm(measure),
    ]),
) as Record<string, ReturnType<typeof createEditForm>>;
const statusForms = Object.fromEntries(
    props.finding.measures.items.map((measure) => [
        measure.id,
        createStatusForm(),
    ]),
) as Record<string, ReturnType<typeof createStatusForm>>;

const statusLabels: Record<MeasureStatus, string> = {
    planned: 'Geplant',
    in_progress: 'In Bearbeitung',
    blocked: 'Blockiert',
    completed: 'Abgeschlossen',
    cancelled: 'Abgebrochen',
};
const transitionLabels: Record<MeasureStatus, string> = {
    ...statusLabels,
    cancelled: 'Abbrechen',
};
const priorityLabels: Record<MeasurePriority, string> = {
    low: 'Niedrig',
    medium: 'Mittel',
    high: 'Hoch',
    critical: 'Kritisch',
};

function createMeasure(): void {
    createForm.post(props.finding.create_measure_url, {
        preserveScroll: true,
        onSuccess: () => createForm.reset(),
    });
}

function updateMeasure(measure: QuestionMeasure): void {
    editForms[measure.id]?.put(measure.update_url, { preserveScroll: true });
}

function transition(measure: QuestionMeasure, status: MeasureStatus): void {
    const form = statusForms[measure.id];

    if (!form) return;
    form.status = status;
    form.patch(measure.transition_url, { preserveScroll: true });
}
</script>

<template>
    <section class="mt-4 rounded-lg border border-slate-800 p-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h5 class="font-medium">Maßnahmen</h5>
            <span class="text-xs text-slate-400">
                {{ finding.measures.terminal }}/{{ finding.measures.total }}
                terminal
            </span>
        </div>

        <form
            v-if="finding.can_create_measure"
            class="mt-4 grid gap-3 md:grid-cols-2"
            @submit.prevent="createMeasure"
        >
            <input
                v-model="createForm.title"
                required
                maxlength="255"
                placeholder="Titel der Maßnahme"
                class="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2"
            />
            <select
                v-model="createForm.priority"
                class="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2"
            >
                <option
                    v-for="(label, value) in priorityLabels"
                    :key="value"
                    :value="value"
                >
                    {{ label }}
                </option>
            </select>
            <textarea
                v-model="createForm.description"
                required
                maxlength="10000"
                rows="3"
                placeholder="Beschreibung"
                class="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 md:col-span-2"
            />
            <input
                v-model="createForm.responsible_name"
                required
                maxlength="255"
                placeholder="Verantwortliche Person"
                class="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2"
            />
            <input
                v-model="createForm.responsible_email"
                type="email"
                maxlength="255"
                placeholder="E-Mail (optional)"
                class="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2"
            />
            <label class="text-sm text-slate-300">
                Frist
                <input
                    v-model="createForm.due_date"
                    type="date"
                    required
                    class="mt-1 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2"
                />
            </label>
            <button
                type="submit"
                :disabled="createForm.processing"
                class="self-end rounded-lg bg-cyan-500 px-4 py-2 font-semibold text-slate-950 disabled:opacity-50"
            >
                Maßnahme anlegen
            </button>
            <p
                v-for="(message, field) in createForm.errors"
                :key="field"
                class="text-sm text-red-300 md:col-span-2"
            >
                {{ message }}
            </p>
            <p
                v-if="createForm.recentlySuccessful"
                class="text-sm text-emerald-300 md:col-span-2"
            >
                Maßnahme angelegt.
            </p>
        </form>

        <div v-if="finding.measures.items.length" class="mt-4 space-y-4">
            <article
                v-for="measure in finding.measures.items"
                :key="measure.id"
                class="rounded-lg bg-slate-950/60 p-4"
            >
                <div class="flex flex-wrap justify-between gap-2">
                    <div>
                        <p class="font-medium">{{ measure.title }}</p>
                        <p class="mt-1 text-sm text-slate-300">
                            {{ measure.description }}
                        </p>
                    </div>
                    <span class="text-xs text-slate-400">
                        {{ priorityLabels[measure.priority] }} ·
                        {{ statusLabels[measure.status] }}
                    </span>
                </div>
                <p class="mt-2 text-xs text-slate-400">
                    {{ measure.responsible_name
                    }}<span v-if="measure.responsible_email">
                        · {{ measure.responsible_email }}</span
                    >
                    · Frist {{ measure.due_date }}
                </p>

                <form
                    v-if="measure.can_edit && editForms[measure.id]"
                    class="mt-3 grid gap-2 md:grid-cols-2"
                    @submit.prevent="updateMeasure(measure)"
                >
                    <input
                        v-model="editForms[measure.id].title"
                        required
                        maxlength="255"
                        class="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm"
                    />
                    <select
                        v-model="editForms[measure.id].priority"
                        class="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm"
                    >
                        <option
                            v-for="(label, value) in priorityLabels"
                            :key="value"
                            :value="value"
                        >
                            {{ label }}
                        </option>
                    </select>
                    <textarea
                        v-model="editForms[measure.id].description"
                        required
                        maxlength="10000"
                        rows="2"
                        class="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm md:col-span-2"
                    />
                    <input
                        v-model="editForms[measure.id].responsible_name"
                        required
                        maxlength="255"
                        class="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm"
                    />
                    <input
                        v-model="editForms[measure.id].responsible_email"
                        type="email"
                        maxlength="255"
                        class="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm"
                    />
                    <input
                        v-model="editForms[measure.id].due_date"
                        type="date"
                        required
                        class="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm"
                    />
                    <button
                        type="submit"
                        :disabled="editForms[measure.id].processing"
                        class="rounded-lg border border-slate-600 px-3 py-2 text-sm"
                    >
                        Details speichern
                    </button>
                    <p
                        v-for="(message, field) in editForms[measure.id].errors"
                        :key="field"
                        class="text-sm text-red-300 md:col-span-2"
                    >
                        {{ message }}
                    </p>
                    <p
                        v-if="editForms[measure.id].recentlySuccessful"
                        class="text-sm text-emerald-300 md:col-span-2"
                    >
                        Maßnahme gespeichert.
                    </p>
                </form>

                <form
                    v-if="
                        measure.allowed_transitions.length &&
                        statusForms[measure.id]
                    "
                    class="mt-3 space-y-2"
                    @submit.prevent
                >
                    <input
                        v-if="measure.allowed_transitions.includes('cancelled')"
                        v-model="statusForms[measure.id].reason"
                        type="text"
                        maxlength="10000"
                        placeholder="Abbruchbegründung"
                        class="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm"
                    />
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-for="status in measure.allowed_transitions"
                            :key="status"
                            type="button"
                            :disabled="
                                statusForms[measure.id].processing ||
                                (status === 'cancelled' &&
                                    !statusForms[measure.id].reason.trim())
                            "
                            class="rounded-lg bg-slate-700 px-3 py-2 text-sm disabled:opacity-50"
                            @click="transition(measure, status)"
                        >
                            {{ transitionLabels[status] }}
                        </button>
                    </div>
                    <p
                        v-for="(message, field) in statusForms[measure.id]
                            .errors"
                        :key="field"
                        class="text-sm text-red-300"
                    >
                        {{ message }}
                    </p>
                    <p
                        v-if="statusForms[measure.id].recentlySuccessful"
                        class="text-sm text-emerald-300"
                    >
                        Status gespeichert.
                    </p>
                </form>
            </article>
        </div>
        <p v-else class="mt-3 text-sm text-slate-500">
            Noch keine Maßnahme vorhanden.
        </p>
    </section>
</template>
