<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import RiskFormFeedback from './RiskFormFeedback.vue';
import type { MethodologyVersion } from '@/types/risk';
const props = defineProps<{
    version: MethodologyVersion;
    canPublish: boolean;
}>();
const base = `/risk-methodologies/${props.version.id}`;
const clone = useForm({ description: '' });
const publish = useForm({});
const draft = useForm({
    levels: {
        frequency: props.version.levels.frequency.map((level) => ({
            ...level,
        })),
        impact: props.version.levels.impact.map((level) => ({ ...level })),
        risk: props.version.levels.risk.map((level) => ({ ...level })),
    },
    cells: props.version.cells.map((cell) => ({ ...cell })),
});
const kinds = ['frequency', 'impact', 'risk'] as const;
</script>
<template>
    <article class="my-6 rounded border border-slate-700 p-5">
        <h2 class="text-xl font-semibold">
            {{ version.name }} · Version {{ version.version }}
        </h2>
        <p>
            {{ version.status }} ·
            {{ version.publishedAt ?? 'Nicht veröffentlicht' }}
        </p>
        <p>{{ version.description }}</p>
        <p>Quelle: {{ version.sourceReference ?? 'Keine Quellenangabe' }}</p>
        <table class="my-4 w-full text-left">
            <caption class="text-left">
                4 × 4 Risikomatrix
            </caption>
            <thead>
                <tr>
                    <th scope="col">Häufigkeit / Auswirkung</th>
                    <th
                        v-for="impact in version.levels.impact"
                        :key="impact.key"
                        scope="col"
                    >
                        {{ impact.label }}
                    </th>
                </tr>
            </thead>
            <tbody>
                <tr
                    v-for="frequency in version.levels.frequency"
                    :key="frequency.key"
                >
                    <th scope="row">{{ frequency.label }}</th>
                    <td
                        v-for="impact in version.levels.impact"
                        :key="impact.key"
                    >
                        {{
                            version.levels.risk.find(
                                (r) =>
                                    r.key ===
                                    version.cells.find(
                                        (c) =>
                                            c.frequency_key === frequency.key &&
                                            c.impact_key === impact.key,
                                    )?.risk_key,
                            )?.label ?? 'Fehlt'
                        }}
                    </td>
                </tr>
            </tbody>
        </table>
        <form
            v-if="canPublish && version.status === 'published'"
            @submit.prevent="
                clone.post(`${base}/clone`, { preserveScroll: true })
            "
        >
            <label
                >Beschreibung des neuen Entwurfs
                <textarea
                    v-model="clone.description"
                    required
                    maxlength="10000"
                /></label
            ><button type="submit" :disabled="clone.processing">
                Als Entwurf klonen</button
            ><RiskFormFeedback
                :errors="clone.errors"
                :success="clone.recentlySuccessful"
            />
        </form>
        <template v-if="canPublish && version.status === 'draft'">
            <form
                @submit.prevent="
                    draft.put(`${base}/draft`, { preserveScroll: true })
                "
            >
                <fieldset :disabled="draft.processing" class="grid gap-3">
                    <section v-for="kind in kinds" :key="kind">
                        <h3 class="my-3 font-semibold">{{ kind }}</h3>
                        <div
                            v-for="(level, index) in draft.levels[kind]"
                            :key="index"
                            class="my-2 grid gap-2"
                        >
                            <label
                                >Schlüssel
                                <input
                                    v-model="level.key"
                                    required
                                    maxlength="64" /></label
                            ><label
                                >Bezeichnung
                                <input
                                    v-model="level.label"
                                    required
                                    maxlength="255" /></label
                            ><label
                                >Beschreibung
                                <textarea
                                    v-model="level.description"
                                    maxlength="10000"
                                /></label
                            ><label
                                >Reihenfolge
                                <input
                                    v-model.number="level.sort_order"
                                    type="number"
                                    min="0"
                                    max="1000"
                                    required
                            /></label>
                        </div>
                    </section>
                    <section>
                        <h3 class="my-3 font-semibold">Matrixzellen</h3>
                        <div
                            v-for="(cell, index) in draft.cells"
                            :key="index"
                            class="my-2"
                        >
                            <label
                                >Häufigkeit
                                <select v-model="cell.frequency_key">
                                    <option
                                        v-for="level in draft.levels.frequency"
                                        :key="level.key"
                                        :value="level.key"
                                    >
                                        {{ level.label }}
                                    </option>
                                </select></label
                            ><label
                                >Auswirkung
                                <select v-model="cell.impact_key">
                                    <option
                                        v-for="level in draft.levels.impact"
                                        :key="level.key"
                                        :value="level.key"
                                    >
                                        {{ level.label }}
                                    </option>
                                </select></label
                            ><label
                                >Kategorie
                                <select v-model="cell.risk_key">
                                    <option
                                        v-for="level in draft.levels.risk"
                                        :key="level.key"
                                        :value="level.key"
                                    >
                                        {{ level.label }}
                                    </option>
                                </select></label
                            >
                        </div>
                    </section>
                    <button type="submit">Entwurf speichern</button>
                </fieldset>
                <RiskFormFeedback
                    :errors="draft.errors"
                    :success="draft.recentlySuccessful"
                />
            </form>
            <button
                type="button"
                :disabled="publish.processing || draft.isDirty"
                @click="
                    publish.post(`${base}/publish`, { preserveScroll: true })
                "
            >
                Gespeicherte Version veröffentlichen
            </button>
            <p v-if="draft.isDirty">
                Änderungen vor Veröffentlichung speichern.
            </p>
            <RiskFormFeedback :errors="publish.errors" />
        </template>
    </article>
</template>
