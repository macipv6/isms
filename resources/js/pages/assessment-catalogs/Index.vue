<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';

type Preview = {
    id: string;
    version: string;
    framework: string;
    count: number;
    expiresAt: string;
    rows: { key: string; title: string; type: string }[];
};
const props = defineProps<{
    frameworks: { id: string; key: string; name: string }[];
    versions: {
        id: string;
        framework: string;
        version: string;
        status: string;
        questions: number;
    }[];
    preview: Preview | null;
}>();
const page = usePage();
const upload = useForm<{
    framework_id: string;
    version: string;
    csv: File | null;
}>({ framework_id: props.frameworks[0]?.id ?? '', version: '', csv: null });
const confirmation = useForm({});
function selectFile(event: Event): void {
    upload.csv = (event.target as HTMLInputElement).files?.[0] ?? null;
}
function submit(): void {
    upload.post('/assessment-catalogs/preview', { forceFormData: true });
}
function confirm(): void {
    if (props.preview)
        confirmation.post(
            `/assessment-catalogs/previews/${props.preview.id}/confirm`,
        );
}
</script>

<template>
    <Head title="Fragekataloge" />
    <AppLayout>
        <h1 class="text-2xl font-semibold">Assessment-Fragekataloge</h1>
        <p class="mt-3 text-slate-300">
            Laden Sie eigene oder rechtmäßig vorhandene Fragen als CSV hoch. Die
            Vorlage enthält ausschließlich ein eigenes Formatbeispiel, keine
            offiziellen BSI-Fragen. Jeder Import veröffentlicht eine neue
            Version; bestehende Assessments behalten ihre Fragen.
        </p>
        <ul
            v-if="Object.keys(page.props.errors).length"
            class="my-4 text-red-300"
            role="alert"
        >
            <li v-for="(error, key) in page.props.errors" :key="key">
                {{ error }}
            </li>
        </ul>
        <section
            v-if="preview"
            class="mt-6 space-y-4 rounded-xl border border-slate-700 p-5"
        >
            <h2 class="text-xl">
                Vorschau: {{ preview.framework }} · {{ preview.version }}
            </h2>
            <p>
                {{ preview.count }} Fragen validiert. Vorschau zeigt maximal 25
                Fragen. Gültig bis
                {{ new Date(preview.expiresAt).toLocaleString('de-DE') }}.
            </p>
            <table class="w-full text-left">
                <thead>
                    <tr>
                        <th>Schlüssel</th>
                        <th>Titel</th>
                        <th>Antworttyp</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in preview.rows" :key="row.key">
                        <td class="py-2">{{ row.key }}</td>
                        <td>{{ row.title }}</td>
                        <td>{{ row.type }}</td>
                    </tr>
                </tbody>
            </table>
            <button
                :disabled="confirmation.processing"
                class="rounded bg-indigo-600 px-4 py-2 disabled:opacity-50"
                @click="confirm"
            >
                Neue Version verbindlich veröffentlichen
            </button>
            <Link href="/assessment-catalogs" class="ml-4 underline"
                >Abbrechen</Link
            >
        </section>
        <template v-else>
            <a
                href="/assessment-catalogs/template"
                class="mt-5 inline-block underline"
                >CSV-Vorlage herunterladen</a
            >
            <form
                class="mt-5 grid max-w-xl gap-4 rounded-xl border border-slate-700 p-5"
                @submit.prevent="submit"
            >
                <label
                    >Framework<select
                        v-model="upload.framework_id"
                        required
                        class="mt-1 block w-full rounded bg-slate-800 p-2"
                    >
                        <option
                            v-for="framework in frameworks"
                            :key="framework.id"
                            :value="framework.id"
                        >
                            {{ framework.name }} ({{ framework.key }})
                        </option>
                    </select></label
                >
                <label
                    >Neue Versionsbezeichnung<input
                        v-model="upload.version"
                        required
                        maxlength="32"
                        pattern="[A-Za-z0-9_.\-]+"
                        class="mt-1 block w-full rounded bg-slate-800 p-2"
                        placeholder="z. B. 2026.2"
                /></label>
                <label
                    >CSV-Datei (UTF-8, maximal 5 MiB / 10.000 Fragen)<input
                        type="file"
                        accept=".csv,text/csv"
                        required
                        class="mt-2 block"
                        @change="selectFile"
                /></label>
                <button
                    :disabled="upload.processing"
                    class="rounded bg-indigo-600 p-2 disabled:opacity-50"
                >
                    Prüfen und Vorschau anzeigen
                </button>
            </form>
            <h2 class="mt-8 text-xl">Vorhandene Versionen</h2>
            <ul class="mt-3 space-y-2">
                <li v-for="version in versions" :key="version.id">
                    {{ version.framework }} · {{ version.version }} ·
                    {{ version.status }} · {{ version.questions }} Fragen
                </li>
            </ul>
        </template>
    </AppLayout>
</template>
