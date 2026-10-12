<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import FormErrorList from '@/components/FormErrorList.vue';
import { nullableInteger, useBcmFormState } from './biaState';
import type { BcmConfiguration } from '@/types/bcm';
const props = defineProps<{
    configuration: BcmConfiguration | null;
    base: string;
    canWrite: boolean;
    lockVersion: number;
}>();
const form = useForm({
    minutesText:
        props.configuration?.minutes.join(', ') ??
        '60, 240, 480, 1440, 4320, 10080',
    lock_version: props.lockVersion,
});
const initializeForm = useForm({});
const state = useBcmFormState(form, () => props.lockVersion);
function activate(): void {
    if (
        !props.canWrite ||
        !props.configuration ||
        state.stale.value ||
        form.processing
    )
        return;
    form.transform((data) => ({
        minutes: data.minutesText
            .split(',')
            .map((value) => nullableInteger(value.trim())),
        lock_version: data.lock_version,
    })).post(`${props.base}/configuration/activate`, {
        preserveScroll: true,
        onHttpException: state.httpException,
        onSuccess: () => {
            state.refreshed();
            form.defaults();
        },
    });
}
function initialize(): void {
    if (props.canWrite && !props.configuration && !initializeForm.processing)
        initializeForm.post(`${props.base}/configuration/initialize`, {
            preserveScroll: true,
        });
}
function reload(): void {
    router.reload({ onSuccess: state.refreshed });
}
</script>
<template>
    <section
        class="mt-6 rounded-xl border border-slate-700 p-4"
        aria-label="BIA-Zeitkonfiguration"
    >
        <h2 class="text-lg font-semibold">Projektzeitstufen</h2>
        <template v-if="configuration">
            <p class="mt-2">
                Aktivierte Version {{ configuration.version }}:
                {{ configuration.minutes.join(', ') }} Minuten
            </p>
            <form v-if="canWrite" class="mt-4" @submit.prevent="activate">
                <label
                    >Neue Zeitstufen (Minuten, durch Komma getrennt)<input
                        v-model="form.minutesText"
                        class="mt-1 block w-full rounded border border-slate-600 bg-slate-950 p-2"
                /></label>
                <p class="mt-2 text-sm text-slate-400">
                    2–12 verschiedene, strikt steigende positive Ganzzahlen bis
                    525600. Bestehende BIA-Versionen behalten ihre Zeitstufen;
                    Entwürfe werden ausdrücklich neu zugeordnet.
                </p>
                <FormErrorList
                    :errors="form.errors"
                    :labels="{
                        minutes: 'Zeitstufen',
                        lock_version: 'Bearbeitungsstand',
                    }"
                    class="mt-3"
                />
                <p
                    v-if="state.stale.value"
                    role="alert"
                    class="mt-3 text-amber-200"
                >
                    Die Konfiguration hat sich geändert. Eingaben bleiben
                    erhalten.
                    <button type="button" class="underline" @click="reload">
                        Aktuellen Stand laden
                    </button>
                </p>
                <button
                    :disabled="form.processing || state.stale.value"
                    class="mt-3 rounded bg-cyan-500 px-4 py-2 font-semibold text-slate-950 disabled:opacity-50"
                >
                    Neue Konfiguration aktivieren
                </button>
            </form>
        </template>
        <template v-else>
            <p class="mt-2">
                Die BIA-Konfiguration ist noch nicht eingerichtet. Standard: 60,
                240, 480, 1440, 4320, 10080 Minuten.
            </p>
            <FormErrorList
                :errors="initializeForm.errors"
                :labels="{}"
                class="mt-3"
            />
            <button
                v-if="canWrite"
                type="button"
                :disabled="initializeForm.processing"
                class="mt-3 rounded bg-cyan-500 px-4 py-2 font-semibold text-slate-950 disabled:opacity-50"
                @click="initialize"
            >
                BIA ausdrücklich initialisieren
            </button>
        </template>
    </section>
</template>
