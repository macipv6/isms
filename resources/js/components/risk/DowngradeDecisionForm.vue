<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import FormErrorList from '@/components/FormErrorList.vue';
import { levelLabels, type PendingDowngrade } from '@/types/risk';

const props = defineProps<{ downgrade: PendingDowngrade; canWrite: boolean }>();
const internalForm = useForm<{
    action: 'internal';
    approved: boolean;
    rationale: string;
}>({
    action: 'internal',
    approved: true,
    rationale: '',
});
const externalForm = useForm<{
    action: 'external';
    approved: boolean;
    name: string;
    function: string;
    decision_date: string;
    rationale: string;
    evidence_id: string;
}>({
    action: 'external',
    approved: true,
    name: '',
    function: '',
    decision_date: '',
    rationale: '',
    evidence_id: '',
});
const labels = {
    approved: 'Entscheidung',
    rationale: 'Begründung',
    name: 'Name',
    function: 'Funktion',
    decision_date: 'Entscheidungsdatum',
    evidence_id: 'Nachweis-ID',
};
function decideInternal(): void {
    if (props.downgrade.actions.decide) {
        internalForm.post(props.downgrade.actions.decide, {
            preserveScroll: true,
            errorBag: 'downgradeInternal',
        });
    }
}
function recordExternal(): void {
    if (props.downgrade.actions.decide) {
        externalForm.post(props.downgrade.actions.decide, {
            preserveScroll: true,
            errorBag: 'downgradeExternal',
        });
    }
}
</script>

<template>
    <section
        class="mt-6 rounded-xl border border-amber-500/40 p-4"
        aria-label="Offener Herabstufungsantrag"
    >
        <h3 class="font-semibold">Offene Herabstufung</h3>
        <p class="mt-1 text-sm text-amber-200">
            {{ levelLabels[downgrade.requestedLevel] }} beantragt; derzeit
            bleibt {{ levelLabels[downgrade.inheritedLevel] }} wirksam.
        </p>
        <form
            v-if="canWrite && downgrade.canDecideInternal"
            class="mt-4 border-t border-slate-700 pt-4"
            @submit.prevent="decideInternal"
        >
            <h4 class="font-medium">Interne Zweitentscheidung</h4>
            <label class="mt-3 block text-sm"
                >Entscheidung
                <select
                    v-model="internalForm.approved"
                    class="mt-1 block w-full rounded border border-slate-600 bg-slate-950 px-3 py-2"
                >
                    <option :value="true">Genehmigen</option>
                    <option :value="false">Ablehnen</option>
                </select>
            </label>
            <label class="mt-3 block text-sm"
                >Begründung
                <textarea
                    v-model="internalForm.rationale"
                    required
                    maxlength="10000"
                    class="mt-1 block w-full rounded border border-slate-600 bg-slate-950 px-3 py-2"
                />
            </label>
            <FormErrorList
                :errors="internalForm.errors"
                :labels="labels"
                class="mt-3"
            />
            <button
                type="submit"
                :disabled="internalForm.processing"
                class="mt-3 rounded bg-cyan-500 px-4 py-2 font-semibold text-slate-950 disabled:opacity-50"
            >
                Interne Entscheidung speichern
            </button>
        </form>

        <form
            v-if="canWrite && downgrade.canRecordExternal"
            class="mt-4 border-t border-slate-700 pt-4"
            @submit.prevent="recordExternal"
        >
            <h4 class="font-medium">
                Externe Managemententscheidung dokumentieren
            </h4>
            <div class="mt-3 grid gap-3 md:grid-cols-2">
                <label class="text-sm"
                    >Entscheidung
                    <select
                        v-model="externalForm.approved"
                        class="mt-1 block w-full rounded border border-slate-600 bg-slate-950 px-3 py-2"
                    >
                        <option :value="true">Genehmigen</option>
                        <option :value="false">Ablehnen</option>
                    </select>
                </label>
                <label class="text-sm"
                    >Entscheidungsdatum
                    <input
                        v-model="externalForm.decision_date"
                        type="date"
                        required
                        class="mt-1 block w-full rounded border border-slate-600 bg-slate-950 px-3 py-2"
                    />
                </label>
                <label class="text-sm"
                    >Name der entscheidenden Person
                    <input
                        v-model="externalForm.name"
                        required
                        maxlength="160"
                        class="mt-1 block w-full rounded border border-slate-600 bg-slate-950 px-3 py-2"
                    />
                </label>
                <label class="text-sm"
                    >Funktion
                    <input
                        v-model="externalForm.function"
                        required
                        maxlength="160"
                        class="mt-1 block w-full rounded border border-slate-600 bg-slate-950 px-3 py-2"
                    />
                </label>
            </div>
            <label class="mt-3 block text-sm"
                >Begründung
                <textarea
                    v-model="externalForm.rationale"
                    required
                    maxlength="10000"
                    class="mt-1 block w-full rounded border border-slate-600 bg-slate-950 px-3 py-2"
                />
            </label>
            <label class="mt-3 block text-sm"
                >Verifizierter Nachweis, ID (optional)
                <input
                    v-model="externalForm.evidence_id"
                    class="mt-1 block w-full rounded border border-slate-600 bg-slate-950 px-3 py-2"
                />
            </label>
            <FormErrorList
                :errors="externalForm.errors"
                :labels="labels"
                class="mt-3"
            />
            <button
                type="submit"
                :disabled="externalForm.processing"
                class="mt-3 rounded bg-emerald-500 px-4 py-2 font-semibold text-slate-950 disabled:opacity-50"
            >
                Externe Entscheidung speichern
            </button>
        </form>
    </section>
</template>
