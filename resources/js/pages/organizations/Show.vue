<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

interface OrganizationDetails {
    id: string;
    name: string;
    industry: string | null;
    employee_count: number | null;
    address: string | null;
    contact_name: string | null;
    contact_email: string | null;
    contact_phone: string | null;
    notes: string | null;
    is_active: boolean;
}

defineProps<{
    organization: OrganizationDetails;
}>();
</script>

<template>
    <Head :title="organization.name" />
    <main class="mx-auto max-w-7xl px-6 py-10">
        <h1 class="text-3xl font-semibold">{{ organization.name }}</h1>
        <p class="mt-3 text-slate-400">
            {{ organization.industry ?? 'Keine Branche hinterlegt' }}
        </p>
    </main>
</template>
