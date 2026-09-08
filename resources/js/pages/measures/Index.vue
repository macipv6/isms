<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import ProjectWorkNavigation from '@/components/ProjectWorkNavigation.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type { OrganizationReference } from '@/types/organization';
import type {
    MeasurePriority,
    MeasureRegisterItem,
    MeasureStatus,
    ProjectWorkReference,
} from '@/types/work-items';

const props = defineProps<{
    organization: OrganizationReference;
    project: ProjectWorkReference;
    filters: {
        status: MeasureStatus | null;
        priority: MeasurePriority | null;
        due_from: string | null;
        due_to: string | null;
    };
    measures: MeasureRegisterItem[];
    canManage: boolean;
}>();

const base = `/organizations/${props.organization.id}/projects/${props.project.id}`;
const statusOptions: Array<{ value: MeasureStatus | ''; label: string }> = [
    { value: '', label: 'Alle Status' },
    { value: 'planned', label: 'Geplant' },
    { value: 'in_progress', label: 'In Bearbeitung' },
    { value: 'blocked', label: 'Blockiert' },
    { value: 'completed', label: 'Abgeschlossen' },
    { value: 'cancelled', label: 'Abgebrochen' },
];
const priorityOptions: Array<{ value: MeasurePriority | ''; label: string }> = [
    { value: '', label: 'Alle Prioritäten' },
    { value: 'critical', label: 'Kritisch' },
    { value: 'high', label: 'Hoch' },
    { value: 'medium', label: 'Mittel' },
    { value: 'low', label: 'Niedrig' },
];
const statusLabels: Record<MeasureStatus, string> = {
    planned: 'Geplant',
    in_progress: 'In Bearbeitung',
    blocked: 'Blockiert',
    completed: 'Abgeschlossen',
    cancelled: 'Abgebrochen',
};
const priorityLabels: Record<MeasurePriority, string> = {
    low: 'Niedrig',
    medium: 'Mittel',
    high: 'Hoch',
    critical: 'Kritisch',
};

const filterForm = useForm<{
    status: MeasureStatus | '';
    priority: MeasurePriority | '';
    due_from: string;
    due_to: string;
}>({
    status: props.filters.status ?? '',
    priority: props.filters.priority ?? '',
    due_from: props.filters.due_from ?? '',
    due_to: props.filters.due_to ?? '',
});
const responsibleSearch = ref('');
const visibleMeasures = computed(() => {
    const search = responsibleSearch.value.trim().toLocaleLowerCase('de-DE');

    if (search === '') return props.measures;

    return props.measures.filter((measure) =>
        `${measure.responsible_name} ${measure.responsible_email ?? ''}`
            .toLocaleLowerCase('de-DE')
            .includes(search),
    );
});

function applyFilters(): void {
    filterForm.get(`${base}/measures`, {
        preserveScroll: true,
        preserveState: true,
        replace: true,
    });
}

function formatDate(value: string): string {
    return new Intl.DateTimeFormat('de-DE', { dateStyle: 'medium' }).format(
        new Date(value),
    );
}
</script>

<template>
    <Head :title="project.name + ' · Maßnahmen'" />
    <AppLayout>
        <Link
            :href="`/organizations/${organization.id}`"
            class="text-sm text-cyan-300 hover:text-cyan-200"
            >← Zum Kunden</Link
        >
        <div class="mt-5">
            <p
                class="text-sm font-semibold tracking-[0.2em] text-slate-400 uppercase"
            >
                {{ organization.name }}
            </p>
            <h1 class="mt-2 text-3xl font-semibold">
                {{ project.name }} · Maßnahmen
            </h1>
            <p class="mt-3 text-slate-400">
                Fristen und Verantwortlichkeiten aus akzeptierten
                Feststellungen.
            </p>
        </div>

        <ProjectWorkNavigation
            :organization-id="organization.id"
            :project-id="project.id"
            active="measures"
        />

        <p
            v-if="!canManage"
            class="mt-6 rounded-lg border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-sm text-amber-200"
        >
            Dieses Projekt ist schreibgeschützt. Frühere Maßnahmen bleiben
            einsehbar.
        </p>

        <form
            class="mt-6 grid gap-4 rounded-2xl border border-slate-800 bg-slate-900/40 p-5 md:grid-cols-2 xl:grid-cols-5"
            @submit.prevent="applyFilters"
        >
            <label class="text-sm text-slate-300">
                Status
                <select
                    v-model="filterForm.status"
                    class="mt-2 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2"
                >
                    <option
                        v-for="option in statusOptions"
                        :key="option.value"
                        :value="option.value"
                    >
                        {{ option.label }}
                    </option>
                </select>
                <span
                    v-if="filterForm.errors.status"
                    class="mt-1 block text-xs text-red-300"
                >
                    {{ filterForm.errors.status }}
                </span>
            </label>
            <label class="text-sm text-slate-300">
                Priorität
                <select
                    v-model="filterForm.priority"
                    class="mt-2 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2"
                >
                    <option
                        v-for="option in priorityOptions"
                        :key="option.value"
                        :value="option.value"
                    >
                        {{ option.label }}
                    </option>
                </select>
                <span
                    v-if="filterForm.errors.priority"
                    class="mt-1 block text-xs text-red-300"
                >
                    {{ filterForm.errors.priority }}
                </span>
            </label>
            <label class="text-sm text-slate-300">
                Fällig ab
                <input
                    v-model="filterForm.due_from"
                    type="date"
                    class="mt-2 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2"
                />
                <span
                    v-if="filterForm.errors.due_from"
                    class="mt-1 block text-xs text-red-300"
                >
                    {{ filterForm.errors.due_from }}
                </span>
            </label>
            <label class="text-sm text-slate-300">
                Fällig bis
                <input
                    v-model="filterForm.due_to"
                    type="date"
                    class="mt-2 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2"
                />
                <span
                    v-if="filterForm.errors.due_to"
                    class="mt-1 block text-xs text-red-300"
                >
                    {{ filterForm.errors.due_to }}
                </span>
            </label>
            <button
                type="submit"
                class="self-end rounded-lg bg-cyan-500 px-4 py-2 font-semibold text-slate-950 hover:bg-cyan-400"
            >
                Filter anwenden
            </button>
        </form>

        <label class="mt-4 block max-w-xl text-sm text-slate-300">
            Verantwortlich
            <input
                v-model="responsibleSearch"
                type="search"
                autocomplete="off"
                placeholder="Name oder E-Mail lokal durchsuchen"
                class="mt-2 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2"
            />
        </label>

        <div v-if="visibleMeasures.length" class="mt-6 space-y-4">
            <article
                v-for="measure in visibleMeasures"
                :key="measure.id"
                class="rounded-2xl border border-slate-800 bg-slate-900/50 p-5"
            >
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p class="text-xs text-slate-500">
                            {{ measure.finding.question_key }} ·
                            {{ measure.finding.title }}
                        </p>
                        <h2 class="mt-2 text-lg font-semibold">
                            {{ measure.title }}
                        </h2>
                        <p class="mt-2 max-w-3xl text-sm text-slate-300">
                            {{ measure.description }}
                        </p>
                    </div>
                    <div class="flex gap-2 text-xs">
                        <span
                            class="rounded-full bg-amber-400/10 px-3 py-1 text-amber-200"
                            >{{ priorityLabels[measure.priority] }}</span
                        >
                        <span
                            class="rounded-full bg-slate-800 px-3 py-1 text-slate-200"
                            >{{ statusLabels[measure.status] }}</span
                        >
                    </div>
                </div>
                <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-slate-500">Verantwortlich</dt>
                        <dd class="mt-1 text-slate-200">
                            {{ measure.responsible_name
                            }}<span v-if="measure.responsible_email">
                                · {{ measure.responsible_email }}</span
                            >
                        </dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Frist</dt>
                        <dd class="mt-1 text-slate-200">
                            {{ formatDate(measure.due_date) }}
                        </dd>
                    </div>
                </dl>
                <Link
                    :href="`${base}/assessment#question-${measure.finding.question_id}`"
                    class="mt-4 inline-flex rounded-lg border border-slate-700 px-4 py-2 text-sm text-cyan-200 hover:bg-slate-800"
                >
                    {{
                        canManage
                            ? 'Im Fragenkontext bearbeiten'
                            : 'Im Fragenkontext ansehen'
                    }}
                </Link>
            </article>
        </div>
        <div
            v-else
            class="mt-6 rounded-2xl border border-dashed border-slate-700 p-10 text-center"
        >
            <p class="font-medium">
                {{
                    responsibleSearch
                        ? 'Keine Maßnahmen entsprechen der lokalen Suche.'
                        : 'Keine Maßnahmen für diesen Filter vorhanden.'
                }}
            </p>
            <p class="mt-2 text-sm text-slate-400">
                Maßnahmen werden aus akzeptierten Feststellungen angelegt.
            </p>
        </div>
    </AppLayout>
</template>
