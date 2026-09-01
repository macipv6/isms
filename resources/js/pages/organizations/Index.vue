<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import type { OrganizationSummary } from '@/types/organization';

defineProps<{
    organizations: OrganizationSummary[];
    canManage: boolean;
}>();
</script>

<template>
    <Head title="Kunden" />
    <AppLayout>
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <p
                    class="text-sm font-semibold tracking-[0.2em] text-slate-400 uppercase"
                >
                    Kundenverwaltung
                </p>
                <h1 class="mt-2 text-3xl font-semibold">Kunden</h1>
                <p class="mt-3 max-w-2xl text-slate-400">
                    Kundenprofile und ihre ISMS-Projekte zentral verwalten.
                </p>
            </div>
            <Link
                v-if="canManage"
                href="/organizations/create"
                class="rounded-lg bg-cyan-500 px-5 py-2.5 font-semibold text-slate-950 hover:bg-cyan-400"
                >Kunde anlegen</Link
            >
        </div>

        <div v-if="organizations.length" class="mt-8 grid gap-4 lg:grid-cols-2">
            <Link
                v-for="organization in organizations"
                :key="organization.id"
                :href="`/organizations/${organization.id}`"
                class="rounded-2xl border border-slate-800 bg-slate-900/50 p-5 transition hover:border-cyan-700 hover:bg-slate-900"
            >
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 class="text-xl font-semibold">
                            {{ organization.name }}
                        </h2>
                        <p class="mt-1 text-sm text-slate-400">
                            {{
                                organization.industry ??
                                'Keine Branche hinterlegt'
                            }}
                        </p>
                    </div>
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
                <dl class="mt-5 grid gap-3 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-slate-500">Mitarbeitende</dt>
                        <dd class="mt-1 text-slate-200">
                            {{ organization.employee_count ?? '–' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Kontakt</dt>
                        <dd class="mt-1 text-slate-200">
                            {{
                                organization.contact_name ??
                                organization.contact_email ??
                                '–'
                            }}
                        </dd>
                    </div>
                </dl>
            </Link>
        </div>

        <div
            v-else
            class="mt-8 rounded-2xl border border-dashed border-slate-700 bg-slate-900/40 p-10 text-center"
        >
            <p class="font-medium">Noch keine Kunden vorhanden.</p>
            <p class="mt-2 text-sm text-slate-400">
                Lege den ersten Kunden an, um ein ISMS-Projekt zu starten.
            </p>
        </div>
    </AppLayout>
</template>
