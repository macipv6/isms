<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
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
            <p
                class="text-sm font-semibold tracking-[0.22em] text-slate-400 uppercase"
            >
                Dashboard
            </p>
            <h1 class="mt-3 text-3xl font-semibold">ISMS Builder</h1>
            <p v-if="user" class="mt-3 text-slate-300">
                Angemeldet als: {{ user.name }} ({{ user.email }})
            </p>
            <div
                class="mt-8 rounded-2xl border border-slate-800 bg-slate-900/50 p-8"
            >
                <p class="text-lg font-medium">Kunden und ISMS-Projekte</p>
                <p class="mt-2 text-sm text-slate-400">
                    Kundenprofile, Scope und Projektstatus zentral verwalten.
                </p>
                <Link
                    href="/organizations"
                    class="mt-5 inline-flex rounded-lg bg-cyan-500 px-5 py-2.5 font-semibold text-slate-950 hover:bg-cyan-400"
                >
                    Kunden öffnen
                </Link>
            </div>
        </section>
    </AppLayout>
</template>
