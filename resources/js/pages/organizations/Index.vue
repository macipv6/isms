<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

interface OrganizationSummary {
    id: string;
    name: string;
    industry: string | null;
    employee_count: number | null;
    contact_name: string | null;
    contact_email: string | null;
    is_active: boolean;
}

defineProps<{
    organizations: OrganizationSummary[];
}>();
</script>

<template>
    <Head title="Kunden" />
    <main class="mx-auto max-w-7xl px-6 py-10">
        <h1 class="text-3xl font-semibold">Kunden</h1>
        <ul class="mt-6 space-y-3">
            <li
                v-for="organization in organizations"
                :key="organization.id"
                class="rounded-xl border border-slate-800 bg-slate-900/40 p-4"
            >
                {{ organization.name }}
            </li>
        </ul>
    </main>
</template>
