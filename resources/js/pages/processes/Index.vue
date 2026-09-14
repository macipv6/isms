<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import ProjectWorkNavigation from '@/components/ProjectWorkNavigation.vue';
import RegisterImportPanel from '@/components/RegisterImportPanel.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type { OrganizationReference } from '@/types/organization';
import type { ProjectWorkReference } from '@/types/work-items';
import type {
    Pagination,
    ProcessEditItem,
    ProcessItem,
    RegisterActions,
    RegisterCapabilities,
    RegisterImportPreviewData,
    RegisterStateFilter,
} from '@/types/registers';

const props = defineProps<{
    organization: OrganizationReference;
    project: ProjectWorkReference;
    filters: { key: string | null; state: RegisterStateFilter | null };
    capabilities: RegisterCapabilities;
    actions: RegisterActions;
    processes: Pagination<ProcessItem>;
    editRecord?: ProcessEditItem;
    importPreview: RegisterImportPreviewData | null;
}>();
const page = usePage<{ flash: { success: string | null } }>();
const base = `/organizations/${props.organization.id}/projects/${props.project.id}/processes`;
const filterForm = useForm({
    key: props.filters.key ?? '',
    state: props.filters.state ?? '',
});
const localSearch = ref('');
const createForm = useForm({
    key: '',
    name: '',
    description: '',
    owner_name: '',
    owner_email: '',
    active: true,
});
const editForm = useForm({
    name: props.editRecord?.name ?? '',
    description: props.editRecord?.description ?? '',
    owner_name: props.editRecord?.owner_name ?? '',
    owner_email: props.editRecord?.owner_email ?? '',
    updated_at: props.editRecord?.updated_at ?? '',
});
const statusForm = useForm({ active: false });
const visible = computed(() => {
    const search = localSearch.value.trim().toLocaleLowerCase('de-DE');
    return search === ''
        ? props.processes.data
        : props.processes.data.filter((item) =>
              `${item.name} ${item.owner_name ?? ''}`
                  .toLocaleLowerCase('de-DE')
                  .includes(search),
          );
});

function applyFilters(): void {
    filterForm.get(base, { preserveState: true, replace: true });
}
function create(): void {
    createForm.post(props.actions.store, {
        preserveScroll: true,
        errorBag: 'processCreate',
        onSuccess: () => createForm.reset(),
    });
}
function update(): void {
    if (props.editRecord)
        editForm.put(`${base}/${props.editRecord.id}`, {
            preserveScroll: true,
            errorBag: 'processEdit',
        });
}
function changeStatus(item: ProcessItem): void {
    statusForm.active = !item.active;
    statusForm.patch(item.actions.status, {
        preserveScroll: true,
        errorBag: 'processStatus',
    });
}
</script>

<template>
    <Head :title="project.name + ' · Prozesse'" />
    <AppLayout>
        <Link
            :href="`/organizations/${organization.id}`"
            class="text-sm text-cyan-300"
            >← Zum Kunden</Link
        >
        <h1 class="mt-5 text-3xl font-semibold">
            {{ project.name }} · Prozesse
        </h1>
        <p class="mt-2 text-slate-400">
            Geschäftsprozesse und ihre Verantwortlichkeiten.
        </p>
        <ProjectWorkNavigation
            :organization-id="organization.id"
            :project-id="project.id"
            active="processes"
        />
        <p
            v-if="!capabilities.create"
            class="mt-6 rounded-lg border border-amber-500/30 bg-amber-500/10 p-4 text-sm text-amber-200"
        >
            Dieses Projekt ist schreibgeschützt. Die Historie bleibt einsehbar.
        </p>
        <p
            v-if="page.props.flash.success"
            class="mt-4 rounded-lg border border-emerald-500/30 bg-emerald-500/10 p-3 text-sm text-emerald-200"
        >
            {{ page.props.flash.success }}
        </p>

        <form
            class="mt-6 grid gap-3 rounded-2xl border border-slate-800 bg-slate-900/40 p-5 md:grid-cols-4"
            @submit.prevent="applyFilters"
        >
            <label class="text-sm"
                >Schlüssel<input
                    v-model="filterForm.key"
                    class="mt-2 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2"
            /></label>
            <label class="text-sm"
                >Status<select
                    v-model="filterForm.state"
                    class="mt-2 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2"
                >
                    <option value="">Alle</option>
                    <option value="active">Aktiv</option>
                    <option value="inactive">Inaktiv</option>
                </select></label
            >
            <label class="text-sm"
                >Name / Verantwortlich (lokal)<input
                    v-model="localSearch"
                    type="search"
                    autocomplete="off"
                    class="mt-2 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2"
            /></label>
            <button
                class="self-end rounded-lg bg-cyan-500 px-4 py-2 font-semibold text-slate-950"
            >
                Filter anwenden
            </button>
            <p
                v-for="message in Object.values(filterForm.errors)"
                :key="message"
                class="text-sm text-red-200"
            >
                {{ message }}
            </p>
        </form>

        <form
            v-if="capabilities.create"
            class="mt-6 grid gap-3 rounded-2xl border border-slate-800 p-5 md:grid-cols-2"
            @submit.prevent="create"
        >
            <h2 class="text-lg font-semibold md:col-span-2">Prozess anlegen</h2>
            <label class="text-sm"
                >Schlüssel<input
                    v-model="createForm.key"
                    required
                    class="mt-2 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2"
            /></label>
            <label class="text-sm"
                >Name<input
                    v-model="createForm.name"
                    required
                    class="mt-2 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2"
            /></label>
            <label class="text-sm"
                >Verantwortlich<input
                    v-model="createForm.owner_name"
                    class="mt-2 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2"
            /></label>
            <label class="text-sm"
                >E-Mail<input
                    v-model="createForm.owner_email"
                    type="email"
                    class="mt-2 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2"
            /></label>
            <label class="text-sm md:col-span-2"
                >Beschreibung<textarea
                    v-model="createForm.description"
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
                {{ editRecord.key }} bearbeiten
            </h2>
            <label class="text-sm"
                >Name<input
                    v-model="editForm.name"
                    required
                    class="mt-2 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2"
            /></label>
            <label class="text-sm"
                >Verantwortlich<input
                    v-model="editForm.owner_name"
                    class="mt-2 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2"
            /></label>
            <label class="text-sm"
                >E-Mail<input
                    v-model="editForm.owner_email"
                    type="email"
                    class="mt-2 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2"
            /></label>
            <label class="text-sm md:col-span-2"
                >Beschreibung<textarea
                    v-model="editForm.description"
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
                        <th class="px-4 py-3">Schlüssel</th>
                        <th class="px-4 py-3">Name</th>
                        <th class="px-4 py-3">Verantwortlich</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800">
                    <tr v-for="item in visible" :key="item.id">
                        <td class="px-4 py-3 font-mono">{{ item.key }}</td>
                        <td class="px-4 py-3">{{ item.name }}</td>
                        <td class="px-4 py-3">{{ item.owner_name ?? '–' }}</td>
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
                v-if="processes.links.previous"
                :href="processes.links.previous"
                >← Zurück</Link
            ><span>{{ processes.meta.total }} Einträge</span
            ><Link v-if="processes.links.next" :href="processes.links.next"
                >Weiter →</Link
            >
        </div>
        <RegisterImportPanel
            :preview-url="actions.previewImport"
            :can-import="capabilities.import"
            :preview="importPreview"
        />
    </AppLayout>
</template>
