<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import type {
    QuestionLinkedEvidence,
    QuestionWorkItems,
} from '@/types/assessment';
import type { EvidenceReviewStatus } from '@/types/work-items';

const props = defineProps<{
    workItems: QuestionWorkItems;
}>();

const uploadForm = useForm<{ file: File | null }>({ file: null });
const linkForm = useForm({});
const selectedLinkUrl = ref('');

function createReviewForm(evidence: QuestionLinkedEvidence) {
    return useForm<{
        status: Exclude<EvidenceReviewStatus, 'pending_review'> | '';
        review_note: string;
    }>({
        status: evidence.status === 'pending_review' ? '' : evidence.status,
        review_note: '',
    });
}

const reviewForms = Object.fromEntries(
    props.workItems.linked_evidence.map((evidence) => [
        evidence.id,
        createReviewForm(evidence),
    ]),
) as Record<string, ReturnType<typeof createReviewForm>>;

function selectFile(event: Event): void {
    uploadForm.file = (event.target as HTMLInputElement).files?.[0] ?? null;
}

function upload(): void {
    uploadForm.post(props.workItems.upload_url, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => uploadForm.reset(),
    });
}

function linkEvidence(): void {
    if (selectedLinkUrl.value === '') return;

    linkForm.post(selectedLinkUrl.value, {
        preserveScroll: true,
        onSuccess: () => {
            selectedLinkUrl.value = '';
        },
    });
}

function review(evidence: QuestionLinkedEvidence): void {
    const form = reviewForms[evidence.id];

    if (!form || form.status === '') return;
    form.patch(evidence.review_url, { preserveScroll: true });
}

function formatSize(bytes: number): string {
    return new Intl.NumberFormat('de-DE', {
        style: 'unit',
        unit: bytes >= 1048576 ? 'megabyte' : 'kilobyte',
        unitDisplay: 'short',
        maximumFractionDigits: 1,
    }).format(bytes >= 1048576 ? bytes / 1048576 : bytes / 1024);
}

const statusLabels: Record<EvidenceReviewStatus, string> = {
    pending_review: 'Zu prüfen',
    verified: 'Bestätigt',
    rejected: 'Abgelehnt',
};
</script>

<template>
    <section class="rounded-xl border border-slate-800 bg-slate-950/40 p-4">
        <h4 class="font-semibold text-slate-100">Nachweise</h4>

        <form
            v-if="workItems.can_upload_evidence"
            class="mt-4 space-y-3"
            @submit.prevent="upload"
        >
            <label class="block text-sm text-slate-300">
                Neue Datei
                <input
                    type="file"
                    required
                    class="mt-2 block w-full text-sm"
                    @change="selectFile"
                />
            </label>
            <progress
                v-if="uploadForm.progress"
                class="h-2 w-full"
                :value="uploadForm.progress.percentage"
                max="100"
            />
            <p
                v-for="(message, field) in uploadForm.errors"
                :key="field"
                class="text-sm text-red-300"
            >
                {{ message }}
            </p>
            <p
                v-if="uploadForm.recentlySuccessful"
                class="text-sm text-emerald-300"
            >
                Nachweis hochgeladen.
            </p>
            <button
                type="submit"
                :disabled="uploadForm.processing || !uploadForm.file"
                class="rounded-lg bg-cyan-500 px-4 py-2 text-sm font-semibold text-slate-950 disabled:opacity-50"
            >
                Datei hochladen
            </button>
        </form>

        <form
            v-if="
                workItems.can_upload_evidence &&
                workItems.available_evidence.length
            "
            class="mt-4 flex flex-wrap items-end gap-3"
            @submit.prevent="linkEvidence"
        >
            <label class="min-w-64 flex-1 text-sm text-slate-300">
                Vorhandenen Nachweis verknüpfen
                <select
                    v-model="selectedLinkUrl"
                    required
                    class="mt-2 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2"
                >
                    <option value="">Bitte auswählen</option>
                    <option
                        v-for="evidence in workItems.available_evidence"
                        :key="evidence.id"
                        :value="evidence.link_url"
                    >
                        {{ evidence.original_name }} ·
                        {{ statusLabels[evidence.status] }}
                    </option>
                </select>
            </label>
            <button
                type="submit"
                :disabled="linkForm.processing || !selectedLinkUrl"
                class="rounded-lg border border-cyan-600 px-4 py-2 text-sm text-cyan-200 disabled:opacity-50"
            >
                Verknüpfen
            </button>
            <p
                v-for="(message, field) in linkForm.errors"
                :key="field"
                class="w-full text-sm text-red-300"
            >
                {{ message }}
            </p>
            <p
                v-if="linkForm.recentlySuccessful"
                class="w-full text-sm text-emerald-300"
            >
                Nachweis verknüpft.
            </p>
        </form>

        <div v-if="workItems.linked_evidence.length" class="mt-4 space-y-3">
            <article
                v-for="evidence in workItems.linked_evidence"
                :key="evidence.id"
                class="rounded-lg border border-slate-800 p-3"
            >
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <p class="text-sm font-medium">
                            {{ evidence.original_name }}
                        </p>
                        <p class="mt-1 text-xs text-slate-400">
                            {{ evidence.file_kind.toUpperCase() }} ·
                            {{ formatSize(evidence.size_bytes) }} ·
                            {{ statusLabels[evidence.status] }}
                        </p>
                    </div>
                    <a
                        :href="evidence.download_url"
                        class="rounded-lg border border-slate-700 px-3 py-2 text-sm text-cyan-200"
                    >
                        Sicher herunterladen
                    </a>
                </div>

                <form
                    v-if="evidence.can_review && reviewForms[evidence.id]"
                    class="mt-3 grid gap-3 md:grid-cols-[180px_1fr_auto]"
                    @submit.prevent="review(evidence)"
                >
                    <select
                        v-model="reviewForms[evidence.id].status"
                        required
                        class="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm"
                    >
                        <option value="">Prüfung wählen</option>
                        <option value="verified">Bestätigen</option>
                        <option value="rejected">Ablehnen</option>
                    </select>
                    <input
                        v-model="reviewForms[evidence.id].review_note"
                        type="text"
                        maxlength="10000"
                        :required="
                            reviewForms[evidence.id].status === 'rejected'
                        "
                        placeholder="Begründung bei Ablehnung"
                        class="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm"
                    />
                    <button
                        type="submit"
                        :disabled="reviewForms[evidence.id].processing"
                        class="rounded-lg bg-slate-700 px-3 py-2 text-sm"
                    >
                        Prüfung speichern
                    </button>
                    <p
                        v-for="(message, field) in reviewForms[evidence.id]
                            .errors"
                        :key="field"
                        class="text-sm text-red-300 md:col-span-3"
                    >
                        {{ message }}
                    </p>
                    <p
                        v-if="reviewForms[evidence.id].recentlySuccessful"
                        class="text-sm text-emerald-300 md:col-span-3"
                    >
                        Prüfung gespeichert.
                    </p>
                </form>
            </article>
        </div>
        <p v-else class="mt-3 text-sm text-slate-500">
            Noch kein Nachweis mit dieser Frage verknüpft.
        </p>
    </section>
</template>
