<script setup lang="ts">
import type { BcmRole } from '@/types/bcm';
const rows = defineModel<BcmRole[]>({ required: true });
defineProps<{ readonly?: boolean }>();
const fields = {
    role: 'Rolle',
    name: 'Verantwortliche Person',
    deputy_name: 'Stellvertretung',
    email: 'E-Mail',
    phone: 'Telefon',
} as const;
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
        <h3 class="font-semibold">Rollen und Kontakte</h3>
        <p class="text-sm text-slate-400">
            Kontaktdaten dokumentieren Verantwortlichkeit und Stellvertretung.
            Sie verleihen keine Kontorechte.
        </p>
        <article
            v-for="(row, index) in rows"
            :key="index"
            class="rounded border border-slate-700 p-3"
        >
            <p>Rolle {{ index + 1 }}</p>
            <div class="grid gap-2 md:grid-cols-2">
                <label v-for="(label, field) in fields" :key="field"
                    >{{ label
                    }}<span v-if="readonly" class="block">{{
                        row[field] || 'Unbekannt'
                    }}</span
                    ><input
                        v-else
                        v-model="row[field]"
                        :type="field === 'email' ? 'email' : 'text'"
                        :maxlength="field === 'email' ? 255 : 160"
                /></label>
            </div>
            <div v-if="!readonly" class="mt-2 flex gap-3">
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
        </article>
        <button
            v-if="!readonly"
            type="button"
            :disabled="rows.length >= 200"
            @click="
                rows.push({
                    role: '',
                    name: '',
                    deputy_name: '',
                    email: '',
                    phone: '',
                })
            "
        >
            Rolle hinzufügen
        </button>
    </section>
</template>
<style scoped>
input {
    display: block;
    width: 100%;
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
