<script setup lang="ts">
import type { BcmStep } from '@/types/bcm';
const rows = defineModel<BcmStep[]>({ required: true });
defineProps<{ readonly?: boolean }>();
function move(index: number, delta: number): void {
    const target = index + delta;
    if (target < 0 || target >= rows.value.length) return;
    const next = [...rows.value];
    [next[index], next[target]] = [next[target], next[index]];
    rows.value = next;
}
</script>
<template>
    <section class="mt-4 space-y-3">
        <h3 class="font-semibold">Geordnete Wiederanlaufschritte</h3>
        <article
            v-for="(row, index) in rows"
            :key="index"
            class="rounded border border-slate-700 p-3"
        >
            <p class="font-medium">Schritt {{ index + 1 }}</p>
            <template v-if="readonly"
                ><p>{{ row.instruction || 'Keine Anweisung' }}</p>
                <p>
                    Verantwortlich: {{ row.owner_name || 'Unbekannt' }} · Dauer:
                    {{ row.expected_minutes ?? 'Unbekannt' }} Minuten
                </p></template
            >
            <template v-else>
                <label class="block"
                    >Anweisung<textarea
                        v-model="row.instruction"
                        maxlength="10000"
                    />
                </label>
                <label
                    >Verantwortliche Person<input
                        v-model="row.owner_name"
                        maxlength="160"
                /></label>
                <label
                    >Erwartete Dauer (Minuten)<input
                        v-model="row.expected_minutes"
                        type="number"
                        min="0"
                        step="1"
                /></label>
                <div class="mt-2 flex gap-3">
                    <button
                        type="button"
                        :disabled="index === 0"
                        @click="move(index, -1)"
                    >
                        Nach oben</button
                    ><button
                        type="button"
                        :disabled="index === rows.length - 1"
                        @click="move(index, 1)"
                    >
                        Nach unten</button
                    ><button type="button" @click="rows.splice(index, 1)">
                        Entfernen
                    </button>
                </div>
            </template>
        </article>
        <button
            v-if="!readonly"
            type="button"
            :disabled="rows.length >= 200"
            @click="
                rows.push({
                    instruction: '',
                    owner_name: '',
                    expected_minutes: null,
                })
            "
        >
            Schritt hinzufügen
        </button>
    </section>
</template>
<style scoped>
input,
textarea {
    display: block;
    width: 100%;
    margin: 0.25rem 0 0.75rem;
    padding: 0.5rem;
    border: 1px solid #475569;
    border-radius: 0.25rem;
    background: #020617;
}
button {
    color: #67e8f9;
}
button:disabled {
    opacity: 0.4;
}
</style>
