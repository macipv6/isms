<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import FormErrorList from '@/components/FormErrorList.vue';
import BiaImpactMatrix from './BiaImpactMatrix.vue';
import {
    biaData,
    minuteLabel,
    nullableInteger,
    useBcmFormState,
} from './biaState';
import type { BiaRevision } from '@/types/bcm';
const props = defineProps<{
    revision: BiaRevision;
    base: string;
    canEdit: boolean;
    canApprove: boolean;
}>();
const emit = defineEmits<{ pending: [value: boolean] }>();
const form =
    props.revision.status === 'draft' && props.canEdit
        ? useForm(biaData(props.revision))
        : null;
const approval = useForm({ lock_version: props.revision.lock_version });
const state = useBcmFormState(
    form ?? approval,
    () => props.revision.lock_version,
);
const dirty = computed(() => form?.isDirty ?? false);
watch(
    () =>
        dirty.value ||
        state.stale.value ||
        !!form?.processing ||
        approval.processing,
    (pending) => emit('pending', pending),
    { immediate: true },
);
const labels = {
    owner_name: 'Verantwortlichkeit',
    mtpd_minutes: 'MTPD',
    rto_minutes: 'RTO',
    rpo_minutes: 'RPO',
    rpo_reason: 'RPO-Begründung',
    target_reason: 'Zielbegründung',
    impacts: 'Auswirkungen',
    lock_version: 'Bearbeitungsstand',
    sources: 'Quellen',
    configuration: 'Zeitstufen',
};
function payload() {
    if (!form) return {};
    return {
        ...form.data(),
        mtpd_minutes: nullableInteger(form.mtpd_minutes),
        rto_minutes: nullableInteger(form.rto_minutes),
        rpo_minutes: form.rpo_not_applicable
            ? null
            : nullableInteger(form.rpo_minutes),
    };
}
function save(): void {
    if (
        !form ||
        !props.canEdit ||
        state.stale.value ||
        form.processing ||
        approval.processing
    )
        return;
    form.transform(payload).put(props.base, {
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
        !props.canApprove ||
        dirty.value ||
        state.stale.value ||
        form?.processing ||
        approval.processing
    )
        return;
    approval.lock_version = form?.lock_version ?? props.revision.lock_version;
    approval.post(`${props.base}/approve`, {
        preserveScroll: true,
        onHttpException: state.httpException,
    });
}
function reload(): void {
    router.reload({
        onSuccess: () => {
            state.refreshed();
            approval.lock_version = props.revision.lock_version;
        },
    });
}
</script>
<template>
    <section class="mt-6 rounded-xl border border-slate-700 p-4">
        <form v-if="form && canEdit" @submit.prevent="save">
            <h2 class="text-lg font-semibold">BIA-Entwurf bearbeiten</h2>
            <div class="mt-3 grid gap-3 md:grid-cols-3">
                <label
                    >Verantwortliche Person<input
                        v-model="form.owner_name"
                        maxlength="160"
                        class="mt-1 block w-full rounded border border-slate-600 bg-slate-950 p-2"
                /></label>
                <label
                    >MTPD (Minuten)<input
                        v-model="form.mtpd_minutes"
                        type="number"
                        min="1"
                        step="1"
                        class="mt-1 block w-full rounded border border-slate-600 bg-slate-950 p-2"
                /></label>
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
                <label class="self-center"
                    ><input v-model="form.rpo_not_applicable" type="checkbox" />
                    RPO nicht anwendbar</label
                >
                <label
                    >Begründung RPO<textarea
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
            <p class="mt-2 text-sm text-slate-400">
                Unvollständige Entwürfe können gespeichert werden. Für die
                Freigabe gilt RTO &lt; MTPD und eine vollständige, begründete
                Matrix.
            </p>
            <BiaImpactMatrix
                :impacts="form.impacts"
                :minutes="revision.minutes"
            />
            <FormErrorList
                :errors="form.errors"
                :labels="labels"
                class="mt-3"
            />
            <button
                :disabled="
                    form.processing || approval.processing || state.stale.value
                "
                class="mt-4 rounded bg-cyan-500 px-4 py-2 font-semibold text-slate-950 disabled:opacity-50"
            >
                Entwurf speichern
            </button>
        </form>
        <div v-else aria-label="BIA schreibgeschützt">
            <h2 class="text-lg font-semibold">
                Gespeicherte BIA · schreibgeschützt
            </h2>
            <p class="mt-3">
                Verantwortlich: {{ revision.owner_name || 'Unbekannt' }}
            </p>
            <p>
                MTPD: {{ minuteLabel(revision.mtpd_minutes) }} · RTO:
                {{ minuteLabel(revision.rto_minutes) }} · RPO:
                {{
                    revision.rpo_not_applicable
                        ? 'Nicht anwendbar'
                        : minuteLabel(revision.rpo_minutes)
                }}
            </p>
            <p>{{ revision.rpo_reason }}</p>
            <p>{{ revision.target_reason }}</p>
            <BiaImpactMatrix
                :impacts="revision.impacts"
                :minutes="revision.minutes"
                readonly
            />
        </div>
        <p v-if="state.stale.value" role="alert" class="mt-3 text-amber-200">
            Der Bearbeitungsstand wurde geändert. Ihre Eingaben bleiben
            erhalten.
            <button type="button" class="underline" @click="reload">
                Aktuellen Stand ausdrücklich laden
            </button>
        </p>
        <FormErrorList
            :errors="approval.errors"
            :labels="labels"
            class="mt-3"
        />
        <p v-if="dirty && canApprove" class="mt-3 text-sm text-amber-200">
            Änderungen vor der Freigabe speichern.
        </p>
        <button
            v-if="canApprove"
            type="button"
            :disabled="
                dirty ||
                state.stale.value ||
                form?.processing ||
                approval.processing
            "
            class="mt-3 rounded bg-emerald-500 px-4 py-2 font-semibold text-slate-950 disabled:opacity-50"
            @click="approve"
        >
            Gespeicherte BIA freigeben
        </button>
    </section>
</template>
