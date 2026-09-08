<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import ProjectWorkNavigation from '@/components/ProjectWorkNavigation.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type { OrganizationReference } from '@/types/organization';
import type {
    FindingRegisterItem,
    FindingSeverity,
    FindingStatus,
    ProjectWorkReference,
} from '@/types/work-items';

const props = defineProps<{
    organization: OrganizationReference;
    project: ProjectWorkReference;
    filters: { status: FindingStatus | null };
    findings: FindingRegisterItem[];
    canManage: boolean;
}>();

const base = `/organizations/${props.organization.id}/projects/${props.project.id}`;
const filterOptions: Array<{ value: FindingStatus | null; label: string }> = [
    { value: null, label: 'Alle' },
    { value: 'proposed', label: 'Vorgeschlagen' },
    { value: 'accepted', label: 'Akzeptiert' },
    { value: 'rejected', label: 'Verworfen' },
    { value: 'closed', label: 'Geschlossen' },
];
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

function filterUrl(status: FindingStatus | null): string {
    return status === null
        ? `${base}/findings`
        : `${base}/findings?status=${status}`;
}

function formatDate(value: string): string {
    return new Intl.DateTimeFormat('de-DE', { dateStyle: 'medium' }).format(
        new Date(value),
    );
}
</script>

<template>
    <Head :title="project.name + ' · Feststellungen'" />
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
                {{ project.name }} · Feststellungen
            </h1>
            <p class="mt-3 text-slate-400">
                Bewertungslücken, Entscheidungen und Umsetzungsstand.
            </p>
        </div>

        <ProjectWorkNavigation
            :organization-id="organization.id"
            :project-id="project.id"
            active="findings"
        />

        <p
            v-if="!canManage"
            class="mt-6 rounded-lg border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-sm text-amber-200"
        >
            Dieses Projekt ist schreibgeschützt. Frühere Feststellungen bleiben
            einsehbar.
        </p>

        <nav
            class="mt-6 flex flex-wrap gap-2"
            aria-label="Feststellungsstatus filtern"
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

        <div v-if="findings.length" class="mt-6 space-y-4">
            <article
                v-for="finding in findings"
                :key="finding.id"
                class="rounded-2xl border border-slate-800 bg-slate-900/50 p-5"
            >
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p class="text-xs text-slate-500">
                            {{ finding.question_key }} ·
                            {{ finding.question_title }}
                        </p>
                        <h2 class="mt-2 text-lg font-semibold">
                            {{ finding.title }}
                        </h2>
                        <p class="mt-2 max-w-3xl text-sm text-slate-300">
                            {{ finding.description }}
                        </p>
                    </div>
                    <div class="flex gap-2 text-xs">
                        <span
                            class="rounded-full bg-amber-400/10 px-3 py-1 text-amber-200"
                            >{{ severityLabels[finding.severity] }}</span
                        >
                        <span
                            class="rounded-full bg-slate-800 px-3 py-1 text-slate-200"
                            >{{ statusLabels[finding.status] }}</span
                        >
                    </div>
                </div>
                <div
                    class="mt-4 flex flex-wrap items-center justify-between gap-3 text-sm"
                >
                    <p class="text-slate-400">
                        Vorgeschlagen am {{ formatDate(finding.proposed_at) }} ·
                        Maßnahmen {{ finding.measures.terminal }}/{{
                            finding.measures.total
                        }}
                        abgeschlossen
                    </p>
                    <Link
                        :href="`${base}/assessment#question-${finding.question_id}`"
                        class="rounded-lg border border-slate-700 px-4 py-2 text-cyan-200 hover:bg-slate-800"
                    >
                        {{
                            canManage
                                ? 'In Bewertung bearbeiten'
                                : 'In Bewertung ansehen'
                        }}
                    </Link>
                </div>
            </article>
        </div>
        <div
            v-else
            class="mt-6 rounded-2xl border border-dashed border-slate-700 p-10 text-center"
        >
            <p class="font-medium">
                Keine Feststellungen für diesen Filter vorhanden.
            </p>
            <p class="mt-2 text-sm text-slate-400">
                Feststellungen werden aus einer teilweise oder nicht erfüllten
                Frage vorgeschlagen.
            </p>
        </div>
    </AppLayout>
</template>
