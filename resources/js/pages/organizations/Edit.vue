<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import OrganizationForm from '@/components/OrganizationForm.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type { OrganizationDetails } from '@/types/organization';

defineProps<{
    organization: OrganizationDetails;
}>();
</script>

<template>
    <Head :title="`${organization.name} bearbeiten`" />
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
                Kundenverwaltung
            </p>
            <h1 class="mt-2 text-3xl font-semibold">
                {{ organization.name }} bearbeiten
            </h1>
            <div
                class="mt-8 rounded-2xl border border-slate-800 bg-slate-900/60 p-6"
            >
                <OrganizationForm :organization="organization" />
            </div>
        </div>
    </AppLayout>
</template>
