<script setup lang="ts">
import PropagationSources from '@/components/risk/PropagationSources.vue';
import {
    levelLabels,
    objectiveLabels,
    type ProtectionLevel,
    type ProtectionNeedRow,
} from '@/types/risk';

defineProps<{
    rows: ProtectionNeedRow[];
    selectedId: string | null;
    canWrite: boolean;
}>();
const emit = defineEmits<{ select: [id: string] }>();
const statusLabels = {
    draft: 'Entwurf',
    assessed: 'Bewertet',
    approved: 'Freigegeben',
};
function level(value: ProtectionLevel | null): string {
    return value ? levelLabels[value] : 'Nicht bewertet';
}
</script>

<template>
    <div class="mt-6 overflow-x-auto rounded-xl border border-slate-800">
        <table class="min-w-full text-left text-sm">
            <caption class="p-4 text-left text-base font-semibold">
                Schutzbedarf je Schutzziel
            </caption>
            <thead class="bg-slate-900 text-slate-300">
                <tr>
                    <th scope="col" class="px-3 py-3">Ziel</th>
                    <th scope="col" class="px-3 py-3">Schutzziel</th>
                    <th scope="col" class="px-3 py-3">Direkt</th>
                    <th scope="col" class="px-3 py-3">Geerbt</th>
                    <th scope="col" class="px-3 py-3">Wirksam</th>
                    <th scope="col" class="px-3 py-3">Status</th>
                    <th scope="col" class="px-3 py-3">Details</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-800">
                <template v-for="row in rows" :key="row.id">
                    <tr :class="selectedId === row.id ? 'bg-cyan-500/10' : ''">
                        <th scope="row" class="px-3 py-3 font-medium">
                            {{ row.target.key }} · {{ row.target.name }}
                            <span
                                v-if="!row.target.active"
                                class="block text-amber-200"
                                >Historisch</span
                            >
                        </th>
                        <td class="px-3 py-3">
                            {{ objectiveLabels[row.objective] }}
                        </td>
                        <td class="px-3 py-3">{{ level(row.directLevel) }}</td>
                        <td class="px-3 py-3">
                            {{ level(row.inheritedLevel) }}
                        </td>
                        <td class="px-3 py-3 font-semibold">
                            {{ level(row.effectiveLevel) }}
                        </td>
                        <td class="px-3 py-3">
                            {{ statusLabels[row.status] }}
                            <span
                                v-if="row.needsReview"
                                class="block text-amber-200"
                                >Prüfung erforderlich</span
                            >
                            <span
                                v-if="row.downgrade"
                                class="block text-amber-200"
                            >
                                Herabstufung auf
                                {{ level(row.downgrade.requestedLevel) }}
                                beantragt – noch nicht wirksam
                            </span>
                        </td>
                        <td class="px-3 py-3">
                            <button
                                type="button"
                                class="rounded border border-cyan-500 px-3 py-1 text-cyan-200"
                                :aria-expanded="selectedId === row.id"
                                :aria-controls="`need-detail-${row.id}`"
                                @click="emit('select', row.id)"
                            >
                                {{
                                    selectedId === row.id
                                        ? 'Schließen'
                                        : canWrite
                                          ? 'Details und Aktionen'
                                          : 'Details'
                                }}
                            </button>
                        </td>
                    </tr>
                    <tr
                        v-if="selectedId === row.id"
                        :id="`need-detail-${row.id}`"
                    >
                        <td colspan="7" class="bg-slate-950/60 px-4 py-4">
                            <p class="mb-4">
                                <strong>Direkte Begründung:</strong>
                                {{
                                    row.directRationale ||
                                    'Noch keine Begründung.'
                                }}
                            </p>
                            <PropagationSources :sources="row.sources" />
                        </td>
                    </tr>
                </template>
                <tr v-if="rows.length === 0">
                    <td colspan="7" class="px-4 py-6 text-slate-400">
                        Keine Einträge für diese Filter.
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
