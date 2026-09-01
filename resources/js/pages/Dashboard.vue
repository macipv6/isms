<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type { AuthProps } from '@/types/auth';

const page = usePage<AuthProps>();
const user = computed(() => page.props.auth.user);
</script>

<template>
    <Head title="Dashboard" />
    <AppLayout>
        <section>
            <p class="text-sm font-semibold uppercase tracking-[0.22em] text-slate-400">Dashboard</p>
            <h1 class="mt-3 text-3xl font-semibold">ISMS Builder</h1>
            <p v-if="user" class="mt-3 text-slate-300">Angemeldet als: {{ user.name }} ({{ user.email }})</p>
            <div class="mt-8 rounded-xl border border-dashed border-slate-700 bg-slate-900/40 p-8">
                <p class="font-medium">Noch keine Kundenprojekte vorhanden.</p>
                <p class="mt-2 text-sm text-slate-400">Die Kunden- und Projektverwaltung folgt im nächsten Delivery-Slice.</p>
            </div>
        </section>
    </AppLayout>
</template>
