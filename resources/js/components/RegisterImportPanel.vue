<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import FormErrorList from '@/components/FormErrorList.vue';
import RegisterImportPreview from '@/components/RegisterImportPreview.vue';
import type { RegisterImportPreviewData } from '@/types/registers';

const props = defineProps<{
    previewUrl: string;
    canImport: boolean;
    preview: RegisterImportPreviewData | null;
}>();
const uploadForm = useForm<{ file: File | null }>({ file: null });

function selectFile(event: Event): void {
    uploadForm.file = (event.target as HTMLInputElement).files?.[0] ?? null;
}

function upload(): void {
    uploadForm.post(props.previewUrl, {
        forceFormData: true,
        preserveScroll: true,
        errorBag: 'registerImportPreview',
        onSuccess: () => uploadForm.reset('file'),
    });
}
</script>

<template>
    <section
        class="mt-8 rounded-2xl border border-slate-800 bg-slate-900/40 p-5"
    >
        <h2 class="text-lg font-semibold">CSV-Import</h2>
        <p class="mt-2 text-sm text-slate-400">
            Die Datei wird zuerst vollständig geprüft. Erst die anschließende
            Bestätigung ändert das Register.
        </p>
        <form
            v-if="canImport"
            class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-end"
            @submit.prevent="upload"
        >
            <label class="flex-1 text-sm text-slate-300"
                >CSV-Datei
                <input
                    type="file"
                    accept=".csv,text/csv"
                    class="mt-2 block w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2"
                    @change="selectFile"
                />
                <span
                    v-if="uploadForm.errors.file"
                    class="mt-1 block text-xs text-red-300"
                    >{{ uploadForm.errors.file }}</span
                >
            </label>
            <button
                type="submit"
                :disabled="!uploadForm.file || uploadForm.processing"
                class="rounded-lg bg-cyan-500 px-4 py-2 font-semibold text-slate-950 disabled:opacity-50"
            >
                {{
                    uploadForm.processing
                        ? `Prüfung ${uploadForm.progress?.percentage ?? 0} %`
                        : 'Vorschau erstellen'
                }}
            </button>
            <FormErrorList
                :errors="uploadForm.errors"
                :labels="{ file: 'CSV-Datei', import: 'Import' }"
                class="sm:w-full"
                title="Die CSV-Datei konnte nicht geprüft werden."
            />
        </form>
        <p v-else class="mt-4 text-sm text-amber-200">
            In diesem Projektzustand sind keine neuen Importe möglich.
        </p>
        <RegisterImportPreview v-if="preview" :preview="preview" />
    </section>
</template>
