<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import FormErrorList from '@/components/FormErrorList.vue';
import type {
    DependencyNodeType,
    DependencyTraversalData,
    TraversalDirection,
} from '@/types/registers';

const props = defineProps<{
    baseUrl: string;
    traversal: DependencyTraversalData | null;
}>();
const form = useForm<{
    node_type: DependencyNodeType;
    node_key: string;
    direction: TraversalDirection;
    transitive: 'true' | 'false';
    include_inactive: 'true' | 'false';
}>({
    node_type: props.traversal?.selected.type ?? 'process',
    node_key: props.traversal?.selected.key ?? '',
    direction: props.traversal?.direction ?? 'dependencies',
    transitive: props.traversal?.transitive ? 'true' : 'false',
    include_inactive: props.traversal?.includeInactive ? 'true' : 'false',
});

function inspect(): void {
    form.get(props.baseUrl, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}
</script>

<template>
    <section
        class="mt-8 rounded-2xl border border-slate-800 bg-slate-900/40 p-5"
    >
        <h2 class="text-lg font-semibold">Abhängigkeiten untersuchen</h2>
        <form
            class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-5"
            @submit.prevent="inspect"
        >
            <label class="text-sm"
                >Typ<select
                    v-model="form.node_type"
                    class="mt-2 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2"
                >
                    <option value="process">Prozess</option>
                    <option value="asset">Asset</option>
                </select></label
            >
            <label class="text-sm"
                >Stabiler Schlüssel<input
                    v-model="form.node_key"
                    required
                    class="mt-2 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 font-mono"
            /></label>
            <label class="text-sm"
                >Richtung<select
                    v-model="form.direction"
                    class="mt-2 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2"
                >
                    <option value="dependencies">Abhängigkeiten</option>
                    <option value="dependents">Abhängige Elemente</option>
                    <option value="affected_processes">
                        Betroffene Prozesse
                    </option>
                </select></label
            >
            <label class="text-sm"
                >Tiefe<select
                    v-model="form.transitive"
                    class="mt-2 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2"
                >
                    <option value="false">Direkt</option>
                    <option value="true">Transitiv</option>
                </select></label
            >
            <div class="flex items-end gap-3">
                <label class="flex items-center gap-2 text-sm"
                    ><input
                        v-model="form.include_inactive"
                        type="checkbox"
                        true-value="true"
                        false-value="false"
                    />Historie</label
                ><button
                    class="rounded-lg bg-cyan-500 px-4 py-2 font-semibold text-slate-950"
                >
                    Anzeigen
                </button>
            </div>
        </form>
        <FormErrorList
            :errors="form.errors"
            :labels="{
                node_type: 'Elementtyp',
                node_key: 'Stabiler Schlüssel',
                direction: 'Richtung',
                transitive: 'Tiefe',
                include_inactive: 'Historie',
            }"
            class="mt-3"
            title="Die Abhängigkeitsauswertung konnte nicht geladen werden."
        />
        <div v-if="traversal" class="mt-5 overflow-x-auto">
            <p class="mb-3 text-sm text-slate-400">
                Ausgangspunkt:
                <span class="font-mono text-slate-200"
                    >{{ traversal.selected.type }}:{{
                        traversal.selected.key
                    }}</span
                >
            </p>
            <table class="min-w-full text-left text-sm">
                <thead class="text-slate-400">
                    <tr>
                        <th class="px-3 py-2">Typ</th>
                        <th class="px-3 py-2">Schlüssel</th>
                        <th class="px-3 py-2">Name</th>
                        <th class="px-3 py-2">Tiefe</th>
                        <th class="px-3 py-2">Bedeutung</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800">
                    <tr
                        v-for="hit in traversal.hits"
                        :key="`${hit.type}:${hit.key}`"
                    >
                        <td class="px-3 py-2">{{ hit.type }}</td>
                        <td class="px-3 py-2 font-mono">{{ hit.key }}</td>
                        <td class="px-3 py-2">{{ hit.name }}</td>
                        <td class="px-3 py-2">{{ hit.depth }}</td>
                        <td class="px-3 py-2">{{ hit.importance }}</td>
                    </tr>
                </tbody>
            </table>
            <p
                v-if="traversal.hits.length === 0"
                class="mt-3 text-sm text-slate-400"
            >
                Keine Treffer.
            </p>
        </div>
    </section>
</template>
