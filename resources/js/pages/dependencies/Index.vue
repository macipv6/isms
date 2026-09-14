<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import DependencyTraversalPanel from '@/components/DependencyTraversalPanel.vue';
import ProjectWorkNavigation from '@/components/ProjectWorkNavigation.vue';
import RegisterImportPanel from '@/components/RegisterImportPanel.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type { OrganizationReference } from '@/types/organization';
import type { ProjectWorkReference } from '@/types/work-items';
import type {
    DependencyImportance,
    DependencyItem,
    DependencyNodeType,
    DependencyTraversalData,
    Pagination,
    RegisterActions,
    RegisterCapabilities,
    RegisterImportPreviewData,
    RegisterStateFilter,
} from '@/types/registers';

const props = defineProps<{
    organization: OrganizationReference;
    project: ProjectWorkReference;
    filters: {
        key: string | null;
        state: RegisterStateFilter | null;
        source_type: DependencyNodeType | null;
        target_type: DependencyNodeType | null;
        importance: DependencyImportance | null;
    };
    capabilities: RegisterCapabilities;
    actions: RegisterActions;
    dependencies: Pagination<DependencyItem>;
    editRecord?: DependencyItem;
    traversal: DependencyTraversalData | null;
    importPreview: RegisterImportPreviewData | null;
}>();
const page = usePage<{ flash: { success: string | null } }>();
const base = `/organizations/${props.organization.id}/projects/${props.project.id}/dependencies`;
const filterForm = useForm({
    key: props.filters.key ?? '',
    state: props.filters.state ?? '',
    source_type: props.filters.source_type ?? '',
    target_type: props.filters.target_type ?? '',
    importance: props.filters.importance ?? '',
});
const createForm = useForm<{
    source_type: DependencyNodeType;
    source_key: string;
    target_type: DependencyNodeType;
    target_key: string;
    importance: DependencyImportance;
    reason: string;
}>({
    source_type: 'process',
    source_key: '',
    target_type: 'asset',
    target_key: '',
    importance: 'supporting',
    reason: '',
});
const editForm = useForm<{
    importance: DependencyImportance;
    reason: string;
    updated_at: string;
}>({
    importance: props.editRecord?.importance ?? 'supporting',
    reason: props.editRecord?.reason ?? '',
    updated_at: props.editRecord?.updated_at ?? '',
});
const statusForm = useForm({ active: false });
function applyFilters(): void {
    filterForm.get(base, { preserveState: true, replace: true });
}
function create(): void {
    createForm.post(props.actions.store, {
        preserveScroll: true,
        errorBag: 'dependencyCreate',
        onSuccess: () => createForm.reset(),
    });
}
function update(): void {
    if (props.editRecord)
        editForm.put(`${base}/${props.editRecord.id}`, {
            preserveScroll: true,
            errorBag: 'dependencyEdit',
        });
}
function changeStatus(item: DependencyItem): void {
    statusForm.active = !item.active;
    statusForm.patch(item.actions.status, {
        preserveScroll: true,
        errorBag: 'dependencyStatus',
    });
}
</script>

<template>
    <Head :title="project.name + ' · Abhängigkeiten'" /><AppLayout>
        <Link
            :href="`/organizations/${organization.id}`"
            class="text-sm text-cyan-300"
            >← Zum Kunden</Link
        >
        <h1 class="mt-5 text-3xl font-semibold">
            {{ project.name }} · Abhängigkeiten
        </h1>
        <p class="mt-2 text-slate-400">
            Gerichtete Beziehungen zwischen Prozessen und Assets.
        </p>
        <ProjectWorkNavigation
            :organization-id="organization.id"
            :project-id="project.id"
            active="dependencies"
        />
        <p
            v-if="!capabilities.create"
            class="mt-6 rounded-lg border border-amber-500/30 bg-amber-500/10 p-4 text-sm text-amber-200"
        >
            Dieses Projekt ist schreibgeschützt. Historie und Auswertungen
            bleiben einsehbar.
        </p>
        <p
            v-if="page.props.flash.success"
            class="mt-4 rounded-lg border border-emerald-500/30 bg-emerald-500/10 p-3 text-sm text-emerald-200"
        >
            {{ page.props.flash.success }}
        </p>
        <form
            class="mt-6 grid gap-3 rounded-2xl border border-slate-800 bg-slate-900/40 p-5 md:grid-cols-3 xl:grid-cols-6"
            @submit.prevent="applyFilters"
        >
            <label class="text-sm"
                >Schlüssel<input
                    v-model="filterForm.key"
                    class="mt-2 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2" /></label
            ><label class="text-sm"
                >Status<select
                    v-model="filterForm.state"
                    class="mt-2 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2"
                >
                    <option value="">Alle</option>
                    <option value="active">Aktiv</option>
                    <option value="inactive">Inaktiv</option>
                </select></label
            ><label class="text-sm"
                >Quelle<select
                    v-model="filterForm.source_type"
                    class="mt-2 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2"
                >
                    <option value="">Alle</option>
                    <option value="process">Prozess</option>
                    <option value="asset">Asset</option>
                </select></label
            ><label class="text-sm"
                >Ziel<select
                    v-model="filterForm.target_type"
                    class="mt-2 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2"
                >
                    <option value="">Alle</option>
                    <option value="process">Prozess</option>
                    <option value="asset">Asset</option>
                </select></label
            ><label class="text-sm"
                >Bedeutung<select
                    v-model="filterForm.importance"
                    class="mt-2 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2"
                >
                    <option value="">Alle</option>
                    <option value="critical">Kritisch</option>
                    <option value="supporting">Unterstützend</option>
                </select></label
            ><button
                class="self-end rounded-lg bg-cyan-500 px-4 py-2 font-semibold text-slate-950"
            >
                Filter anwenden
            </button>
        </form>
        <form
            v-if="capabilities.create"
            class="mt-6 grid gap-3 rounded-2xl border border-slate-800 p-5 md:grid-cols-2 xl:grid-cols-4"
            @submit.prevent="create"
        >
            <h2 class="text-lg font-semibold md:col-span-2 xl:col-span-4">
                Abhängigkeit anlegen
            </h2>
            <label class="text-sm"
                >Quelltyp<select
                    v-model="createForm.source_type"
                    class="mt-2 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2"
                >
                    <option value="process">Prozess</option>
                    <option value="asset">Asset</option>
                </select></label
            ><label class="text-sm"
                >Quellschlüssel<input
                    v-model="createForm.source_key"
                    required
                    class="mt-2 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 font-mono" /></label
            ><label class="text-sm"
                >Zieltyp<select
                    v-model="createForm.target_type"
                    class="mt-2 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2"
                >
                    <option value="process">Prozess</option>
                    <option value="asset">Asset</option>
                </select></label
            ><label class="text-sm"
                >Zielschlüssel<input
                    v-model="createForm.target_key"
                    required
                    class="mt-2 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 font-mono" /></label
            ><label class="text-sm"
                >Bedeutung<select
                    v-model="createForm.importance"
                    class="mt-2 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2"
                >
                    <option value="critical">Kritisch</option>
                    <option value="supporting">Unterstützend</option>
                </select></label
            ><label class="text-sm md:col-span-2"
                >Begründung<textarea
                    v-model="createForm.reason"
                    class="mt-2 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2"
                />
            </label>
            <p
                v-for="message in Object.values(createForm.errors)"
                :key="message"
                class="text-sm text-red-200"
            >
                {{ message }}
            </p>
            <button
                class="rounded-lg bg-emerald-500 px-4 py-2 font-semibold text-slate-950"
            >
                Speichern
            </button>
        </form>
        <form
            v-if="editRecord && capabilities.edit"
            class="mt-6 grid gap-3 rounded-2xl border border-cyan-500/30 p-5 md:grid-cols-2"
            @submit.prevent="update"
        >
            <h2 class="text-lg font-semibold md:col-span-2">
                {{ editRecord.source.key }} →
                {{ editRecord.target.key }} bearbeiten
            </h2>
            <label class="text-sm"
                >Bedeutung<select
                    v-model="editForm.importance"
                    class="mt-2 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2"
                >
                    <option value="critical">Kritisch</option>
                    <option value="supporting">Unterstützend</option>
                </select></label
            ><label class="text-sm"
                >Begründung<textarea
                    v-model="editForm.reason"
                    class="mt-2 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2"
                />
            </label>
            <p
                v-for="message in Object.values(editForm.errors)"
                :key="message"
                class="text-sm text-red-200"
            >
                {{ message }}
            </p>
            <button
                class="rounded-lg bg-cyan-500 px-4 py-2 font-semibold text-slate-950"
            >
                Änderungen speichern
            </button>
        </form>
        <div class="mt-6 overflow-x-auto rounded-2xl border border-slate-800">
            <table class="min-w-full text-left text-sm">
                <thead class="bg-slate-900 text-slate-400">
                    <tr>
                        <th class="px-4 py-3">Quelle</th>
                        <th class="px-4 py-3">Ziel</th>
                        <th class="px-4 py-3">Bedeutung</th>
                        <th class="px-4 py-3">Begründung</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800">
                    <tr v-for="item in dependencies.data" :key="item.id">
                        <td class="px-4 py-3">
                            <span class="text-xs text-slate-500">{{
                                item.source.type
                            }}</span>
                            <span class="font-mono">{{ item.source.key }}</span
                            ><br />{{ item.source.name }}
                        </td>
                        <td class="px-4 py-3">
                            <span class="text-xs text-slate-500">{{
                                item.target.type
                            }}</span>
                            <span class="font-mono">{{ item.target.key }}</span
                            ><br />{{ item.target.name }}
                        </td>
                        <td class="px-4 py-3">
                            {{
                                item.importance === 'critical'
                                    ? 'Kritisch'
                                    : 'Unterstützend'
                            }}
                        </td>
                        <td class="px-4 py-3">{{ item.reason ?? '–' }}</td>
                        <td class="px-4 py-3">
                            {{ item.active ? 'Aktiv' : 'Inaktiv' }}
                        </td>
                        <td class="px-4 py-3">
                            <div v-if="capabilities.edit" class="flex gap-3">
                                <Link
                                    :href="`${base}?edit=${item.id}`"
                                    class="text-cyan-300"
                                    >Bearbeiten</Link
                                ><button
                                    type="button"
                                    class="text-amber-200"
                                    @click="changeStatus(item)"
                                >
                                    {{
                                        item.active
                                            ? 'Deaktivieren'
                                            : 'Reaktivieren'
                                    }}
                                </button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="mt-4 flex justify-between text-sm">
            <Link
                v-if="dependencies.links.previous"
                :href="dependencies.links.previous"
                >← Zurück</Link
            ><span>{{ dependencies.meta.total }} Einträge</span
            ><Link
                v-if="dependencies.links.next"
                :href="dependencies.links.next"
                >Weiter →</Link
            >
        </div>
        <DependencyTraversalPanel
            :base-url="base"
            :traversal="traversal"
        /><RegisterImportPanel
            :preview-url="actions.previewImport"
            :can-import="capabilities.import"
            :preview="importPreview"
        />
    </AppLayout>
</template>
