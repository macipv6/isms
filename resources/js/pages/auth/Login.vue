<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
</script>

<template>
    <Head title="Anmelden" />
    <main class="flex min-h-screen items-center justify-center bg-slate-950 px-6 text-slate-100">
        <section class="w-full max-w-md rounded-2xl border border-slate-800 bg-slate-900 p-8 shadow-2xl">
            <p class="text-sm font-semibold uppercase tracking-[0.22em] text-slate-400">ISMS Builder</p>
            <h1 class="mt-3 text-2xl font-semibold">Berater-Anmeldung</h1>
            <p class="mt-2 text-sm leading-6 text-slate-400">Die Anmeldung erfolgt ausschließlich über den freigegebenen Microsoft-Entra-Tenant.</p>
            <a href="/auth/microsoft/redirect" class="mt-8 flex w-full items-center justify-center rounded-lg bg-white px-4 py-3 font-medium text-slate-950 transition hover:bg-slate-200">
                Mit Microsoft anmelden
            </a>
        </section>
    </main>
</template>
