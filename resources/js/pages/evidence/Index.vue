<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import ProjectWorkNavigation from '@/components/ProjectWorkNavigation.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type { OrganizationReference } from '@/types/organization';
import type {
    EvidenceRegisterItem,
    EvidenceReviewStatus,
    ProjectWorkReference,
} from '@/types/work-items';

const props = defineProps<{
    organization: OrganizationReference;
    project: ProjectWorkReference;
    filters: { status: EvidenceReviewStatus | null };
    evidence: EvidenceRegisterItem[];
    canManage: boolean;
}>();

const base = `/organizations/${props.organization.id}/projects/${props.project.id}`;
const filterOptions: Array<{
    value: EvidenceReviewStatus | null;
    label: string;
}> = [
    { value: null, label: 'Alle' },
    { value: 'pending_review', label: 'Zu prüfen' },
    { value: 'verified', label: 'Bestätigt' },
    { value: 'rejected', label: 'Abgelehnt' },
];
const statusLabels: Record<EvidenceReviewStatus, string> = {
    pending_review: 'Zu prüfen',
    verified: 'Bestätigt',
    rejected: 'Abgelehnt',
};

function filterUrl(status: EvidenceReviewStatus | null): string {
    return status === null
        ? `${base}/evidence`
        : `${base}/evidence?status=${status}`;
}

function formatSize(bytes: number): string {
    return new Intl.NumberFormat('de-DE', {
        style: 'unit',
        unit: bytes >= 1048576 ? 'megabyte' : 'kilobyte',
        unitDisplay: 'short',
        maximumFractionDigits: 1,
    }).format(bytes >= 1048576 ? bytes / 1048576 : bytes / 1024);
}

function formatDate(value: string): string {
    return new Intl.DateTimeFormat('de-DE', { dateStyle: 'medium' }).format(
        new Date(value),
    );
}
</script>

<template>
    <Head :title="project.name + ' · Nachweise'" />
    <AppLayout>
        <Link
            :href="`/organizations/${organization.id}`"
            class="text-sm text-cyan-300 hover:text-cyan-200"
        >
            ← Zum Kunden
        </Link>
        <div class="mt-5">
            <p
                class="text-sm font-semibold tracking-[0.2em] text-slate-400 uppercase"
            >
                {{ organization.name }}
            </p>
            <h1 class="mt-2 text-3xl font-semibold">
                {{ project.name }} · Nachweise
            </h1>
            <p class="mt-3 text-slate-400">
                Projektweit verknüpfte Dateien und ihr Prüfstatus.
            </p>
        </div>

        <ProjectWorkNavigation
            :organization-id="organization.id"
            :project-id="project.id"
            active="evidence"
        />

        <p
            v-if="!canManage"
            class="mt-6 rounded-lg border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-sm text-amber-200"
        >
            Dieses Projekt ist schreibgeschützt. Vorhandene Nachweise bleiben
            einsehbar.
        </p>

        <nav
            class="mt-6 flex flex-wrap gap-2"
            aria-label="Nachweisstatus filtern"
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
            >
                {{ filter.label }}
            </Link>
        </nav>

        <div v-if="evidence.length" class="mt-6 space-y-4">
            <article
                v-for="item in evidence"
                :key="item.id"
                class="rounded-2xl border border-slate-800 bg-slate-900/50 p-5"
            >
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h2 class="text-lg font-semibold">
                            {{ item.original_name }}
                        </h2>
                        <p class="mt-1 text-sm text-slate-400">
                            {{ item.file_kind.toUpperCase() }} ·
                            {{ formatSize(item.size_bytes) }} ·
                            {{ formatDate(item.uploaded_at) }}
                        </p>
                    </div>
                    <span
                        class="rounded-full bg-slate-800 px-3 py-1 text-xs text-slate-200"
                    >
                        {{ statusLabels[item.status] }}
                    </span>
                </div>

                <div class="mt-4 flex flex-wrap gap-2">
                    <Link
                        v-for="question in item.questions"
                        :key="question.id"
                        :href="`${base}/assessment#question-${question.id}`"
                        class="rounded-lg border border-slate-700 px-3 py-2 text-sm text-cyan-200 hover:bg-slate-800"
                    >
                        {{ question.question_key }} · {{ question.title }}
                    </Link>
                    <span
                        v-for="finding in item.findings"
                        :key="finding.id"
                        class="rounded-lg bg-slate-800 px-3 py-2 text-sm text-slate-300"
                    >
                        Feststellung: {{ finding.title }}
                    </span>
                </div>

                <a
                    :href="item.download_url"
                    class="mt-4 inline-flex rounded-lg bg-cyan-500 px-4 py-2 text-sm font-semibold text-slate-950 hover:bg-cyan-400"
                >
                    Sicher herunterladen
                </a>
            </article>
        </div>
        <div
            v-else
            class="mt-6 rounded-2xl border border-dashed border-slate-700 p-10 text-center"
        >
            <p class="font-medium">
                Keine Nachweise für diesen Filter vorhanden.
            </p>
            <p class="mt-2 text-sm text-slate-400">
                Nachweise werden direkt an Bewertungsfragen hochgeladen.
            </p>
        </div>
    </AppLayout>
</template>
