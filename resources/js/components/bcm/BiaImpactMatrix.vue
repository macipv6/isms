<script setup lang="ts">
import { computed } from 'vue';
import { firstCriticalMinute, minuteLabel } from './biaState';
import { impactAreas, impactLevels, type BiaImpact } from '@/types/bcm';
const props = defineProps<{
    impacts: BiaImpact[];
    minutes: number[];
    readonly?: boolean;
}>();
const firstCritical = computed(() => firstCriticalMinute(props.impacts));
</script>
<template>
    <section aria-label="Auswirkungsmatrix" class="mt-5">
        <h2 class="text-lg font-semibold">Auswirkungen nach Ausfalldauer</h2>
        <p class="mt-2 text-sm text-slate-400">
            Erste kritische Auswirkung: {{ minuteLabel(firstCritical) }}.
            Orientierung für die Prüfung; Ziele werden manuell festgelegt.
        </p>
        <div class="mt-3 overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr>
                        <th class="p-2 text-left">Bereich</th>
                        <th
                            v-for="minute in minutes"
                            :key="minute"
                            class="p-2 text-left"
                        >
                            {{ minute }} Minuten
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(label, area) in impactAreas" :key="area">
                        <th class="p-2 text-left">{{ label }}</th>
                        <td
                            v-for="minute in minutes"
                            :key="minute"
                            class="min-w-44 p-2 align-top"
                        >
                            <template
                                v-for="cell in impacts.filter(
                                    (row) =>
                                        row.area === area &&
                                        row.minutes === minute,
                                )"
                                :key="`${area}-${minute}`"
                            >
                                <template v-if="readonly"
                                    ><p>
                                        {{
                                            cell.level === null
                                                ? 'Unbekannt'
                                                : impactLevels[cell.level]
                                        }}
                                    </p>
                                    <p class="mt-1 text-slate-400">
                                        {{ cell.reason || 'Keine Begründung' }}
                                    </p></template
                                >
                                <template v-else>
                                    <label
                                        ><span class="sr-only"
                                            >{{ label }} bei
                                            {{ minute }} Minuten – Stufe</span
                                        ><select
                                            v-model="cell.level"
                                            class="w-full rounded border border-slate-600 bg-slate-950 p-2"
                                        >
                                            <option :value="null">
                                                Unbekannt
                                            </option>
                                            <option
                                                v-for="(
                                                    levelLabel, level
                                                ) in impactLevels"
                                                :key="level"
                                                :value="Number(level)"
                                            >
                                                {{ levelLabel }}
                                            </option>
                                        </select></label
                                    >
                                    <label
                                        ><span class="sr-only"
                                            >{{ label }} bei
                                            {{ minute }} Minuten –
                                            Begründung</span
                                        ><textarea
                                            v-model="cell.reason"
                                            maxlength="10000"
                                            class="mt-2 w-full rounded border border-slate-600 bg-slate-950 p-2"
                                        />
                                    </label>
                                </template>
                            </template>
                            <p
                                v-if="
                                    !impacts.some(
                                        (row) =>
                                            row.area === area &&
                                            row.minutes === minute,
                                    )
                                "
                            >
                                Unbekannt
                            </p>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</template>
