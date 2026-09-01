<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import type { OrganizationReference } from '@/types/organization';
import type {
    ProjectDefaults,
    ProjectDetails,
    ProjectStatus,
} from '@/types/project';

const props = defineProps<{
    organization: OrganizationReference;
    defaults?: ProjectDefaults;
    project?: ProjectDetails;
}>();

const form = useForm({
    name: props.project?.name ?? '',
    description: props.project?.description ?? '',
    framework: props.project?.framework ?? props.defaults?.framework ?? 'BSI',
    approach:
        props.project?.approach ??
        props.defaults?.approach ??
        'basis_absicherung',
    bcm_level:
        props.project?.bcm_level ?? props.defaults?.bcm_level ?? 'aufbau_bcms',
    status: (props.project?.status ??
        props.defaults?.status ??
        'draft') as ProjectStatus,
    scope_text: props.project?.scope_text ?? '',
    started_at: props.project?.started_at ?? '',
    target_date: props.project?.target_date ?? '',
    completed_at: props.project?.completed_at ?? '',
});

function submit(): void {
    if (props.project) {
        form.put(
            `/organizations/${props.organization.id}/projects/${props.project.id}`,
        );
        return;
    }

    form.post(`/organizations/${props.organization.id}/projects`);
}
</script>

<template>
    <form class="space-y-6" @submit.prevent="submit">
        <div class="grid gap-6 md:grid-cols-2">
            <label class="block md:col-span-2">
                <span class="text-sm font-medium text-slate-200"
                    >Projektname</span
                >
                <input
                    v-model="form.name"
                    required
                    maxlength="255"
                    class="mt-2 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2"
                />
                <span
                    v-if="form.errors.name"
                    class="mt-1 block text-sm text-red-300"
                    >{{ form.errors.name }}</span
                >
            </label>

            <label class="block md:col-span-2">
                <span class="text-sm font-medium text-slate-200"
                    >Beschreibung</span
                >
                <textarea
                    v-model="form.description"
                    rows="3"
                    maxlength="10000"
                    class="mt-2 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2"
                />
                <span
                    v-if="form.errors.description"
                    class="mt-1 block text-sm text-red-300"
                    >{{ form.errors.description }}</span
                >
            </label>

            <label class="block">
                <span class="text-sm font-medium text-slate-200"
                    >Framework</span
                >
                <select
                    v-model="form.framework"
                    class="mt-2 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2"
                >
                    <option value="BSI">BSI IT-Grundschutz</option>
                </select>
            </label>

            <label class="block">
                <span class="text-sm font-medium text-slate-200">Status</span>
                <select
                    v-model="form.status"
                    class="mt-2 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2"
                >
                    <option value="draft">Entwurf</option>
                    <option value="active">Aktiv</option>
                    <option value="completed">Abgeschlossen</option>
                    <option value="archived">Archiviert</option>
                </select>
                <span
                    v-if="form.errors.status"
                    class="mt-1 block text-sm text-red-300"
                    >{{ form.errors.status }}</span
                >
            </label>

            <label class="block">
                <span class="text-sm font-medium text-slate-200"
                    >Vorgehensweise</span
                >
                <select
                    v-model="form.approach"
                    class="mt-2 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2"
                >
                    <option value="basis_absicherung">Basis-Absicherung</option>
                </select>
            </label>

            <label class="block">
                <span class="text-sm font-medium text-slate-200"
                    >BCM-Level</span
                >
                <select
                    v-model="form.bcm_level"
                    class="mt-2 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2"
                >
                    <option value="aufbau_bcms">Aufbau-BCMS</option>
                </select>
            </label>

            <label class="block md:col-span-2">
                <span class="text-sm font-medium text-slate-200">Scope</span>
                <textarea
                    v-model="form.scope_text"
                    rows="5"
                    maxlength="20000"
                    class="mt-2 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2"
                />
                <span
                    v-if="form.errors.scope_text"
                    class="mt-1 block text-sm text-red-300"
                    >{{ form.errors.scope_text }}</span
                >
            </label>

            <label class="block">
                <span class="text-sm font-medium text-slate-200"
                    >Startdatum</span
                >
                <input
                    v-model="form.started_at"
                    type="date"
                    class="mt-2 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2"
                />
                <span
                    v-if="form.errors.started_at"
                    class="mt-1 block text-sm text-red-300"
                    >{{ form.errors.started_at }}</span
                >
            </label>

            <label class="block">
                <span class="text-sm font-medium text-slate-200"
                    >Zieldatum</span
                >
                <input
                    v-model="form.target_date"
                    type="date"
                    class="mt-2 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2"
                />
                <span
                    v-if="form.errors.target_date"
                    class="mt-1 block text-sm text-red-300"
                    >{{ form.errors.target_date }}</span
                >
            </label>

            <label class="block">
                <span class="text-sm font-medium text-slate-200"
                    >Abschlussdatum</span
                >
                <input
                    v-model="form.completed_at"
                    type="date"
                    class="mt-2 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2"
                />
                <span
                    v-if="form.errors.completed_at"
                    class="mt-1 block text-sm text-red-300"
                    >{{ form.errors.completed_at }}</span
                >
            </label>
        </div>

        <button
            type="submit"
            :disabled="form.processing"
            class="rounded-lg bg-cyan-500 px-5 py-2.5 font-semibold text-slate-950 disabled:opacity-50"
        >
            {{ props.project ? 'Änderungen speichern' : 'Projekt anlegen' }}
        </button>
    </form>
</template>
