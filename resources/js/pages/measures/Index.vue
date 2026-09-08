<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
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
    filters: { status: MeasureStatus | null };
    measures: MeasureRegisterItem[];
    canManage: boolean;
}>();

const base = `/organizations/${props.organization.id}/projects/${props.project.id}`;
const filterOptions: Array<{ value: MeasureStatus | null; label: string }> = [
    { value: null, label: 'Alle' },
    { value: 'planned', label: 'Geplant' },
    { value: 'in_progress', label: 'In Bearbeitung' },
    { value: 'blocked', label: 'Blockiert' },
    { value: 'completed', label: 'Abgeschlossen' },
    { value: 'cancelled', label: 'Abgebrochen' },
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

function filterUrl(status: MeasureStatus | null): string {
    return status === null
        ? `${base}/measures`
        : `${base}/measures?status=${status}`;
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

        <nav
            class="mt-6 flex flex-wrap gap-2"
            aria-label="Maßnahmenstatus filtern"
        >
            <Link
                v-for="filter in filterOptions"
                :key="filter.value ?? 'all'"
                :href="filterUrl(filter.value)"
                :class="
                    filters.status === filter.value
                        ? 'border-cyan-500 bg-cyan-500/10 text-cyan-100'
                        : 'border-slate-700 text-slate-300 hover:bg-slate-900'
                "
                class="rounded-full border px-4 py-2 text-sm"
                >{{ filter.label }}</Link
            >
        </nav>

        <div v-if="measures.length" class="mt-6 space-y-4">
            <article
                v-for="measure in measures"
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
                Keine Maßnahmen für diesen Filter vorhanden.
            </p>
            <p class="mt-2 text-sm text-slate-400">
                Maßnahmen werden aus akzeptierten Feststellungen angelegt.
            </p>
        </div>
    </AppLayout>
</template>
