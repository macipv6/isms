<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import type { OrganizationDetails } from '@/types/organization';
import type { ProjectStatus, ProjectSummary } from '@/types/project';

defineProps<{
    organization: OrganizationDetails;
    projects: ProjectSummary[];
    canManage: boolean;
}>();

const statusLabels: Record<ProjectStatus, string> = {
    draft: 'Entwurf',
    active: 'Aktiv',
    completed: 'Abgeschlossen',
    archived: 'Archiviert',
};

function deactivate(organizationId: string): void {
    if (window.confirm('Kunden wirklich deaktivieren?')) {
        router.patch(`/organizations/${organizationId}/deactivate`);
    }
}
</script>

<template>
    <Head :title="organization.name" />
    <AppLayout>
        <Link
            href="/organizations"
            class="text-sm text-cyan-300 hover:text-cyan-200"
            >← Zur Kundenliste</Link
        >

        <div class="mt-5 flex flex-wrap items-start justify-between gap-4">
            <div>
                <div class="flex flex-wrap items-center gap-3">
                    <h1 class="text-3xl font-semibold">
                        {{ organization.name }}
                    </h1>
                    <span
                        :class="
                            organization.is_active
                                ? 'bg-emerald-400/10 text-emerald-300'
                                : 'bg-slate-700 text-slate-300'
                        "
                        class="rounded-full px-3 py-1 text-xs font-medium"
                        >{{
                            organization.is_active ? 'Aktiv' : 'Inaktiv'
                        }}</span
                    >
                </div>
                <p class="mt-3 text-slate-400">
                    {{ organization.industry ?? 'Keine Branche hinterlegt' }}
                </p>
            </div>
            <div v-if="canManage" class="flex flex-wrap gap-3">
                <Link
                    :href="`/organizations/${organization.id}/edit`"
                    class="rounded-lg border border-slate-700 px-4 py-2 text-sm font-medium hover:bg-slate-800"
                    >Kunde bearbeiten</Link
                >
                <button
                    v-if="organization.is_active"
                    type="button"
                    class="rounded-lg border border-red-900 px-4 py-2 text-sm font-medium text-red-200 hover:bg-red-950/50"
                    @click="deactivate(organization.id)"
                >
                    Deaktivieren
                </button>
            </div>
        </div>

        <section
            class="mt-8 grid gap-4 rounded-2xl border border-slate-800 bg-slate-900/50 p-6 md:grid-cols-2 lg:grid-cols-3"
        >
            <div>
                <p class="text-xs tracking-wide text-slate-500 uppercase">
                    Mitarbeitende
                </p>
                <p class="mt-2">{{ organization.employee_count ?? '–' }}</p>
            </div>
            <div>
                <p class="text-xs tracking-wide text-slate-500 uppercase">
                    Ansprechperson
                </p>
                <p class="mt-2">{{ organization.contact_name ?? '–' }}</p>
            </div>
            <div>
                <p class="text-xs tracking-wide text-slate-500 uppercase">
                    E-Mail
                </p>
                <p class="mt-2">{{ organization.contact_email ?? '–' }}</p>
            </div>
            <div>
                <p class="text-xs tracking-wide text-slate-500 uppercase">
                    Telefon
                </p>
                <p class="mt-2">{{ organization.contact_phone ?? '–' }}</p>
            </div>
            <div class="md:col-span-2">
                <p class="text-xs tracking-wide text-slate-500 uppercase">
                    Adresse
                </p>
                <p class="mt-2 whitespace-pre-line">
                    {{ organization.address ?? '–' }}
                </p>
            </div>
            <div v-if="organization.notes" class="md:col-span-2 lg:col-span-3">
                <p class="text-xs tracking-wide text-slate-500 uppercase">
                    Interne Notizen
                </p>
                <p class="mt-2 whitespace-pre-line text-slate-300">
                    {{ organization.notes }}
                </p>
            </div>
        </section>

        <section class="mt-10">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <p
                        class="text-sm font-semibold tracking-[0.2em] text-slate-400 uppercase"
                    >
                        ISMS
                    </p>
                    <h2 class="mt-2 text-2xl font-semibold">Projekte</h2>
                </div>
                <Link
                    v-if="canManage && organization.is_active"
                    :href="`/organizations/${organization.id}/projects/create`"
                    class="rounded-lg bg-cyan-500 px-5 py-2.5 font-semibold text-slate-950 hover:bg-cyan-400"
                    >ISMS-Projekt anlegen</Link
                >
            </div>

            <div v-if="projects.length" class="mt-6 space-y-4">
                <article
                    v-for="project in projects"
                    :key="project.id"
                    class="rounded-2xl border border-slate-800 bg-slate-900/50 p-5"
                >
                    <div
                        class="flex flex-wrap items-start justify-between gap-4"
                    >
                        <div>
                            <h3 class="text-lg font-semibold">
                                {{ project.name }}
                            </h3>
                            <p class="mt-2 text-sm text-slate-400">
                                {{ project.framework }} · Basis-Absicherung ·
                                Aufbau-BCMS
                            </p>
                        </div>
                        <div class="flex items-center gap-3">
                            <span
                                class="rounded-full bg-cyan-400/10 px-3 py-1 text-xs font-medium text-cyan-200"
                                >{{ statusLabels[project.status] }}</span
                            >
                            <Link
                                v-if="canManage && organization.is_active"
                                :href="`/organizations/${organization.id}/projects/${project.id}/edit`"
                                class="text-sm text-cyan-300 hover:text-cyan-200"
                                >Bearbeiten</Link
                            >
                        </div>
                    </div>
                    <p class="mt-4 text-sm text-slate-400">
                        Start: {{ project.started_at ?? 'offen' }} · Ziel:
                        {{ project.target_date ?? 'offen' }}
                    </p>
                </article>
            </div>
            <div
                v-else
                class="mt-6 rounded-2xl border border-dashed border-slate-700 bg-slate-900/40 p-8 text-center text-slate-400"
            >
                Für diesen Kunden ist noch kein ISMS-Projekt angelegt.
            </div>
        </section>
    </AppLayout>
</template>
