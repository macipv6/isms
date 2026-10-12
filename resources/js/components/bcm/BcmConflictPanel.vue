<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import { reactive, watch } from 'vue';
import FormErrorList from '@/components/FormErrorList.vue';
import { conflictLabel, useBcmFormState } from './biaState';
import type { BcmConflict, BcmPagination } from '@/types/bcm';
const props = defineProps<{
    conflicts: BcmPagination<BcmConflict>;
    base: string;
    lockVersion: number;
    canAccept: boolean;
    editsPending?: boolean;
}>();
function conflictForm(fingerprint: string) {
    return useForm({
        fingerprint,
        reason: '',
        lock_version: props.lockVersion,
    });
}
const forms = reactive(new Map<string, ReturnType<typeof conflictForm>>());
watch(
    () => props.conflicts.data.map((warning) => warning.fingerprint),
    (fingerprints) => {
        const current = new Set(fingerprints);
        for (const fingerprint of forms.keys()) {
            if (!current.has(fingerprint)) forms.delete(fingerprint);
        }
        for (const fingerprint of current) {
            if (!forms.has(fingerprint))
                forms.set(fingerprint, conflictForm(fingerprint));
        }
    },
    { immediate: true },
);
const generation = { lock_version: props.lockVersion };
const state = useBcmFormState(generation, () => props.lockVersion);
function accept(warning: BcmConflict): void {
    const form = forms.get(warning.fingerprint);
    if (
        !form ||
        warning.accepted ||
        !props.canAccept ||
        props.editsPending ||
        state.stale.value ||
        form.processing
    )
        return;
    form.lock_version = props.lockVersion;
    form.post(`${props.base}/conflicts`, {
        preserveScroll: true,
        onHttpException: state.httpException,
    });
}
function reload(): void {
    router.reload({ onSuccess: state.refreshed });
}
</script>
<template>
    <section
        class="mt-6 rounded-xl border border-slate-700 p-4"
        aria-label="Zielkonflikte"
    >
        <h2 class="text-lg font-semibold">Aktuelle Konfliktprüfung</h2>
        <p class="mt-2 text-sm text-slate-400">
            Warnungen und Akzeptanzen gelten für die angezeigten Quellen.
            Geänderte Quellen erfordern eine neue Prüfung.
        </p>
        <p v-if="!conflicts.data.length" class="mt-3">
            Keine Warnungen auf dieser Seite.
        </p>
        <article
            v-for="warning in conflicts.data"
            :key="warning.fingerprint"
            class="mt-4 rounded border border-slate-800 p-3"
        >
            <h3 class="font-semibold">{{ conflictLabel(warning.code) }}</h3>
            <p class="mt-1 text-sm">
                {{
                    warning.path_edge_ids.length
                        ? warning.blocking
                            ? 'Kritischer Pfad / begründungspflichtiger Konflikt'
                            : 'Unterstützender Pfad / Hinweis'
                        : 'Begründungspflichtiger Bewertungshinweis'
                }}
                · {{ warning.target_type === 'asset' ? 'Asset' : 'Prozess' }}
                {{ warning.target_name ?? warning.target_id }}
            </p>
            <p
                v-if="warning.path_edge_ids.length"
                class="mt-1 text-sm text-slate-400"
            >
                Belegter Abhängigkeitspfad:
                {{ warning.path_edge_ids.length }} Kanten
            </p>
            <details
                v-if="warning.path_edge_ids.length"
                class="mt-2 text-xs text-slate-400"
            >
                <summary>Technische Pfadbelege</summary>
                <p>{{ warning.path_edge_ids.join(' → ') }}</p>
            </details>
            <p v-if="warning.accepted" class="mt-2 text-emerald-200">
                Für diese Quellen begründet akzeptiert.
            </p>
            <form
                v-else-if="canAccept && forms.has(warning.fingerprint)"
                class="mt-3"
                @submit.prevent="accept(warning)"
            >
                <label
                    >Begründung der Akzeptanz<textarea
                        v-model="forms.get(warning.fingerprint)!.reason"
                        required
                        maxlength="10000"
                        class="mt-1 block w-full rounded border border-slate-600 bg-slate-950 p-2"
                    />
                </label>
                <FormErrorList
                    :errors="forms.get(warning.fingerprint)!.errors"
                    :labels="{
                        reason: 'Begründung',
                        fingerprint: 'Quellstand',
                        lock_version: 'Bearbeitungsstand',
                    }"
                    class="mt-3"
                />
                <button
                    :disabled="
                        forms.get(warning.fingerprint)!.processing ||
                        state.stale.value ||
                        editsPending
                    "
                    class="mt-3 rounded bg-amber-400 px-4 py-2 font-semibold text-slate-950 disabled:opacity-50"
                >
                    Konflikt ausdrücklich akzeptieren
                </button>
            </form>
        </article>
        <p v-if="state.stale.value" role="alert" class="mt-3 text-amber-200">
            Die Quellen oder die Revision wurden geändert.
            <button type="button" class="underline" @click="reload">
                Aktuellen Stand laden
            </button>
        </p>
        <nav class="mt-4 flex gap-4 text-sm" aria-label="Konfliktseiten">
            <Link v-if="conflicts.prev_page_url" :href="conflicts.prev_page_url"
                >Zurück</Link
            ><span
                >Seite {{ conflicts.current_page }} von
                {{ conflicts.last_page }}</span
            ><Link
                v-if="conflicts.next_page_url"
                :href="conflicts.next_page_url"
                >Weiter</Link
            >
        </nav>
    </section>
</template>
