<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import ProjectForm from '@/components/ProjectForm.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type { OrganizationReference } from '@/types/organization';
import type { ProjectDetails } from '@/types/project';

defineProps<{
    organization: OrganizationReference;
    project: ProjectDetails;
}>();
</script>

<template>
    <Head :title="`${project.name} bearbeiten`" />
    <AppLayout>
        <Link
            :href="`/organizations/${organization.id}`"
            class="text-sm text-cyan-300 hover:text-cyan-200"
            >← Zum Kunden</Link
        >
        <div class="mt-5 max-w-4xl">
            <p
                class="text-sm font-semibold tracking-[0.2em] text-slate-400 uppercase"
            >
                {{ organization.name }}
            </p>
            <h1 class="mt-2 text-3xl font-semibold">
                {{ project.name }} bearbeiten
            </h1>
            <div
                class="mt-8 rounded-2xl border border-slate-800 bg-slate-900/60 p-6"
            >
                <ProjectForm :organization="organization" :project="project" />
            </div>
        </div>
    </AppLayout>
</template>
