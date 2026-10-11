<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import DowngradeDecisionForm from '@/components/risk/DowngradeDecisionForm.vue';
import ProtectionNeedForm from '@/components/risk/ProtectionNeedForm.vue';
import ProtectionNeedTable from '@/components/risk/ProtectionNeedTable.vue';
import ProjectWorkNavigation from '@/components/ProjectWorkNavigation.vue';
import RegisterImportPanel from '@/components/RegisterImportPanel.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type { ProtectionNeedPage } from '@/types/risk';

const props = defineProps<ProtectionNeedPage>();
const page = usePage<{
    flash: { success: string | null; error?: string | null };
}>();
const base = `/organizations/${props.organization.id}/projects/${props.project.id}/protection-needs`;
const selectedId = ref<string | null>(null);
const selectedNeed = computed(
    () => props.needs.data.find((row) => row.id === selectedId.value) ?? null,
);
const filterForm = useForm({
    target_type: props.filters.targetType ?? '',
    state: props.filters.state,
});
function select(id: string): void {
    selectedId.value = selectedId.value === id ? null : id;
}
function filter(): void {
    filterForm.get(base, { preserveState: true, replace: true });
}
</script>

<template>
    <Head :title="project.name + ' · Schutzbedarf'" />
    <AppLayout>
        <Link
            :href="`/organizations/${organization.id}`"
            class="text-sm text-cyan-300"
            >← Zum Kunden</Link
        >
        <h1 class="mt-5 text-3xl font-semibold">
            {{ project.name }} · Schutzbedarf
        </h1>
        <p class="mt-2 text-slate-400">
            Direkte, geerbte und wirksame Werte je Schutzziel.
        </p>
        <ProjectWorkNavigation
            :organization-id="organization.id"
            :project-id="project.id"
            active="protection-needs"
        />

        <p
            v-if="!capabilities.write"
            class="mt-6 rounded-lg border border-amber-500/30 bg-amber-500/10 p-4 text-sm text-amber-200"
        >
            Dieses Projekt ist schreibgeschützt. Historische Bewertungen und
            Entscheidungen bleiben einsehbar.
        </p>
        <p
            v-if="page.props.flash.success"
            role="status"
            class="mt-4 rounded-lg border border-emerald-500/30 bg-emerald-500/10 p-3 text-sm text-emerald-200"
        >
            {{ page.props.flash.success }}
        </p>
        <p
            v-if="page.props.flash.error"
            role="alert"
            class="mt-4 rounded-lg border border-red-500/30 bg-red-500/10 p-3 text-sm text-red-200"
        >
            {{ page.props.flash.error }}
        </p>

        <form
            class="mt-6 flex flex-wrap items-end gap-3 rounded-xl border border-slate-800 p-4"
            @submit.prevent="filter"
        >
            <label class="text-sm"
                >Zieltyp
                <select
                    v-model="filterForm.target_type"
                    class="mt-1 block rounded border border-slate-600 bg-slate-950 px-3 py-2"
                >
                    <option value="">Alle</option>
                    <option value="process">Prozesse</option>
                    <option value="asset">Assets</option>
                </select>
            </label>
            <label class="text-sm"
                >Status
                <select
                    v-model="filterForm.state"
                    class="mt-1 block rounded border border-slate-600 bg-slate-950 px-3 py-2"
                >
                    <option value="all">Alle</option>
                    <option value="active">Aktiv</option>
                    <option value="inactive">Historisch</option>
                </select>
            </label>
            <button
                type="submit"
                :disabled="filterForm.processing"
                class="rounded bg-cyan-500 px-4 py-2 font-semibold text-slate-950 disabled:opacity-50"
            >
                Filter anwenden
            </button>
        </form>

        <ProtectionNeedTable
            :rows="needs.data"
            :selected-id="selectedId"
            :can-write="capabilities.write"
            @select="select"
        />
        <nav
            class="mt-4 flex gap-4 text-sm"
            aria-label="Seiten der Schutzbedarfsbewertung"
        >
            <Link
                v-if="needs.links.previous"
                :href="needs.links.previous"
                class="text-cyan-300"
                >← Vorherige Seite</Link
            >
            <span
                >Seite {{ needs.meta.currentPage }} von
                {{ needs.meta.lastPage }}</span
            >
            <Link
                v-if="needs.links.next"
                :href="needs.links.next"
                class="text-cyan-300"
                >Nächste Seite →</Link
            >
        </nav>

        <section
            v-if="selectedNeed"
            class="mt-8 rounded-2xl border border-cyan-500/30 bg-slate-900/40 p-5"
            aria-label="Ausgewählter Schutzbedarf"
        >
            <h2 class="text-xl font-semibold">
                {{ selectedNeed.target.key }} · {{ selectedNeed.target.name }}
            </h2>
            <p class="mt-1 text-sm text-slate-400">
                {{
                    selectedNeed.objective === 'confidentiality'
                        ? 'Vertraulichkeit'
                        : selectedNeed.objective === 'integrity'
                          ? 'Integrität'
                          : 'Verfügbarkeit'
                }}
            </p>
            <ProtectionNeedForm
                :key="`${selectedNeed.id}-${selectedNeed.expectedVersions.confidentiality}-${selectedNeed.expectedVersions.integrity}-${selectedNeed.expectedVersions.availability}`"
                :need="selectedNeed"
                :can-write="capabilities.write"
            />
            <DowngradeDecisionForm
                v-if="selectedNeed.downgrade"
                :key="selectedNeed.downgrade.id"
                :downgrade="selectedNeed.downgrade"
                :can-write="capabilities.write"
            />
        </section>

        <RegisterImportPanel
            kind="protection_needs"
            :preview-url="actions.previewImport"
            :can-import="capabilities.import"
            :preview="importPreview"
        />
    </AppLayout>
</template>
