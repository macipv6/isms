<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import FormErrorList from '@/components/FormErrorList.vue';
import type { RegisterImportPreviewData } from '@/types/registers';

const props = defineProps<{ preview: RegisterImportPreviewData }>();
const confirmForm = useForm({});
const categoryLabels = {
    new: 'Neu',
    changed: 'Geändert',
    unchanged: 'Unverändert',
    invalid: 'Ungültig',
};

function confirm(): void {
    confirmForm.post(props.preview.actions.confirm, {
        preserveScroll: true,
        errorBag: 'registerImportConfirm',
    });
}
</script>

<template>
    <section
        class="mt-5 rounded-xl border border-slate-700 bg-slate-950/60 p-4"
    >
        <div class="flex flex-wrap gap-3 text-sm">
            <span
                class="rounded-full bg-emerald-400/10 px-3 py-1 text-emerald-200"
                >Neu: {{ preview.counts.new }}</span
            >
            <span class="rounded-full bg-cyan-400/10 px-3 py-1 text-cyan-200"
                >Geändert: {{ preview.counts.changed }}</span
            >
            <span class="rounded-full bg-slate-800 px-3 py-1 text-slate-300"
                >Unverändert: {{ preview.counts.unchanged }}</span
            >
            <span class="rounded-full bg-red-400/10 px-3 py-1 text-red-200"
                >Ungültig: {{ preview.counts.invalid }}</span
            >
        </div>

        <p v-if="preview.expired" class="mt-4 text-sm text-amber-200">
            Diese Vorschau ist abgelaufen. Bitte laden Sie die CSV erneut hoch.
        </p>
        <p
            v-else-if="preview.status === 'applied'"
            class="mt-4 text-sm text-emerald-200"
        >
            Der Import wurde gespeichert.
        </p>
        <p
            v-else-if="preview.counts.invalid > 0"
            class="mt-4 text-sm text-red-200"
        >
            Beheben Sie die gemeldeten Zeilen und erstellen Sie eine neue
            Vorschau.
        </p>

        <div v-if="preview.rows.length" class="mt-4 overflow-x-auto">
            <table class="min-w-full text-left text-sm">
                <thead class="text-slate-400">
                    <tr>
                        <th class="px-3 py-2">Zeile</th>
                        <th class="px-3 py-2">Referenz</th>
                        <th class="px-3 py-2">Ergebnis</th>
                        <th class="px-3 py-2">Feld / Fehler</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800">
                    <tr
                        v-for="(row, index) in preview.rows"
                        :key="`${row.line ?? 'file'}-${index}`"
                    >
                        <td class="px-3 py-2">{{ row.line ?? 'Datei' }}</td>
                        <td class="px-3 py-2 font-mono text-xs">
                            {{ row.reference ?? '–' }}
                        </td>
                        <td class="px-3 py-2">
                            {{ categoryLabels[row.category] }}
                        </td>
                        <td class="px-3 py-2">
                            {{
                                [row.field, row.code]
                                    .filter(Boolean)
                                    .join(' · ') || '–'
                            }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <button
            v-if="preview.canConfirm"
            type="button"
            :disabled="confirmForm.processing"
            class="mt-5 rounded-lg bg-emerald-500 px-4 py-2 font-semibold text-slate-950 disabled:opacity-50"
            @click="confirm"
        >
            {{
                confirmForm.processing
                    ? 'Wird gespeichert …'
                    : 'Import verbindlich übernehmen'
            }}
        </button>
        <FormErrorList
            :errors="confirmForm.errors"
            :labels="{ import: 'Import' }"
            class="mt-3"
            title="Der Import konnte nicht übernommen werden."
        />
    </section>
</template>
