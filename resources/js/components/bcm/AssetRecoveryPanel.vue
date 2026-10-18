<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import FormErrorList from '@/components/FormErrorList.vue';
import {
    minuteLabel,
    nullableInteger,
    recoveryData,
    useBcmFormState,
} from './biaState';
import type { BcmCapabilities, RecoveryRevision } from '@/types/bcm';
const props = defineProps<{
    revision: RecoveryRevision | null;
    base: string;
    capabilities: BcmCapabilities;
}>();
const form =
    props.revision?.status === 'draft' && props.capabilities.edit
        ? useForm(recoveryData(props.revision))
        : null;
const action = useForm({ lock_version: props.revision?.lock_version ?? 0 });
const state = useBcmFormState(
    form ?? action,
    () => props.revision?.lock_version ?? 0,
);
const labels = {
    rto_minutes: 'RTO',
    rpo_minutes: 'RPO',
    rpo_reason: 'RPO-Begründung',
    target_reason: 'Zielbegründung',
    lock_version: 'Bearbeitungsstand',
};
function save(): void {
    if (
        !form ||
        !props.capabilities.edit ||
        !props.revision ||
        state.stale.value ||
        form.processing ||
        action.processing
    )
        return;
    form.transform((data) => ({
        ...data,
        rto_minutes: nullableInteger(data.rto_minutes),
        rpo_minutes: data.rpo_not_applicable
            ? null
            : nullableInteger(data.rpo_minutes),
    })).put(`${props.base}/${props.revision.id}`, {
        preserveScroll: true,
        onHttpException: state.httpException,
        onSuccess: () => {
            state.refreshed();
            form.defaults();
        },
    });
}
function approve(): void {
    if (
        !props.capabilities.approve ||
        !props.revision ||
        state.stale.value ||
        form?.isDirty ||
        form?.processing ||
        action.processing
    )
        return;
    action.lock_version = form?.lock_version ?? props.revision.lock_version;
    action.post(`${props.base}/${props.revision.id}/approve`, {
        preserveScroll: true,
        onHttpException: state.httpException,
    });
}
function draft(): void {
    if (props.capabilities.draft && !action.processing && !state.stale.value)
        action.transform(() => ({})).post(props.base);
}
function reload(): void {
    router.reload({ onSuccess: state.refreshed });
}
</script>
<template>
    <section class="mt-6 rounded-xl border border-slate-700 p-4">
        <form v-if="form && capabilities.edit" @submit.prevent="save">
            <h2 class="text-lg font-semibold">Wiederanlaufziele bearbeiten</h2>
            <div class="mt-3 grid gap-3 md:grid-cols-2">
                <label
                    >RTO (Minuten)<input
                        v-model="form.rto_minutes"
                        type="number"
                        min="1"
                        step="1"
                        class="mt-1 block w-full rounded border border-slate-600 bg-slate-950 p-2"
                /></label>
                <label
                    >RPO (Minuten)<input
                        v-model="form.rpo_minutes"
                        :disabled="form.rpo_not_applicable"
                        type="number"
                        min="0"
                        step="1"
                        class="mt-1 block w-full rounded border border-slate-600 bg-slate-950 p-2"
                /></label>
                <label
                    ><input v-model="form.rpo_not_applicable" type="checkbox" />
                    RPO nicht anwendbar</label
                >
                <label
                    >RPO-Begründung<textarea
                        v-model="form.rpo_reason"
                        maxlength="10000"
                        class="mt-1 block w-full rounded border border-slate-600 bg-slate-950 p-2"
                    />
                </label>
            </div>
            <label class="mt-3 block"
                >Zielbegründung<textarea
                    v-model="form.target_reason"
                    maxlength="10000"
                    class="mt-1 block w-full rounded border border-slate-600 bg-slate-950 p-2"
                />
            </label>
            <FormErrorList
                :errors="form.errors"
                :labels="labels"
                class="mt-3"
            />
            <button
                :disabled="
                    form.processing || action.processing || state.stale.value
                "
                class="mt-3 rounded bg-cyan-500 px-4 py-2 font-semibold text-slate-950 disabled:opacity-50"
            >
                Entwurf speichern
            </button>
        </form>
        <div v-else>
            <h2 class="text-lg font-semibold">
                Gespeicherte Wiederanlaufziele · schreibgeschützt
            </h2>
            <p v-if="!revision" class="mt-3">
                RTO: Unbekannt · RPO: Unbekannt. Es wurde noch kein Zielentwurf
                angelegt.
            </p>
            <template v-else
                ><p class="mt-3">
                    RTO: {{ minuteLabel(revision.rto_minutes) }} · RPO:
                    {{
                        revision.rpo_not_applicable
                            ? 'Nicht anwendbar'
                            : minuteLabel(revision.rpo_minutes)
                    }}
                </p>
                <p>{{ revision.rpo_reason }}</p>
                <p>{{ revision.target_reason }}</p></template
            >
        </div>
        <p v-if="state.stale.value" role="alert" class="mt-3 text-amber-200">
            Der Bearbeitungsstand wurde geändert. Eingaben bleiben erhalten.
            <button type="button" class="underline" @click="reload">
                Aktuellen Stand laden
            </button>
        </p>
        <FormErrorList :errors="action.errors" :labels="labels" class="mt-3" />
        <p
            v-if="form?.isDirty && capabilities.approve"
            class="mt-3 text-sm text-amber-200"
        >
            Änderungen vor der Freigabe speichern.
        </p>
        <button
            v-if="capabilities.approve"
            type="button"
            :disabled="
                state.stale.value ||
                form?.isDirty ||
                form?.processing ||
                action.processing
            "
            class="mt-3 rounded bg-emerald-500 px-4 py-2 font-semibold text-slate-950 disabled:opacity-50"
            @click="approve"
        >
            Gespeicherte Ziele freigeben
        </button>
        <button
            v-if="capabilities.draft && revision?.status !== 'draft'"
            type="button"
            :disabled="action.processing || state.stale.value"
            class="mt-3 rounded bg-cyan-500 px-4 py-2 font-semibold text-slate-950 disabled:opacity-50"
            @click="draft"
        >
            {{
                revision
                    ? 'Neue Zielrevision anlegen'
                    : 'Zielentwurf ausdrücklich anlegen'
            }}
        </button>
    </section>
</template>
