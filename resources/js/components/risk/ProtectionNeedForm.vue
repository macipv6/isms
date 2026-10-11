<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import FormErrorList from '@/components/FormErrorList.vue';
import {
    levelLabels,
    objectiveLabels,
    type ProtectionLevel,
    type ProtectionNeedRow,
    type ProtectionObjective,
    type ProtectionVersions,
} from '@/types/risk';

const props = defineProps<{ need: ProtectionNeedRow; canWrite: boolean }>();
const levels: ProtectionLevel[] = ['normal', 'high', 'very_high'];
const ratingForm = useForm<{
    ratings: Partial<
        Record<
            ProtectionObjective,
            { level: ProtectionLevel; rationale: string }
        >
    >;
    expected_versions: ProtectionVersions;
}>({
    ratings: {
        [props.need.objective]: {
            level: props.need.directLevel ?? 'normal',
            rationale: props.need.directRationale,
        },
    },
    expected_versions: props.need.expectedVersions,
});
const approvalForm = useForm<{ expected_versions: ProtectionVersions }>({
    expected_versions: props.need.expectedVersions,
});
const requestForm = useForm<{
    action: 'request';
    requested_level: ProtectionLevel;
    rationale: string;
}>({
    action: 'request',
    requested_level: props.need.directLevel ?? 'normal',
    rationale: '',
});
const ratingConflict = computed(() => 'lock_version' in ratingForm.errors);
const approvalConflict = computed(() => 'lock_version' in approvalForm.errors);
const downgradeLevels = computed(() =>
    levels.filter(
        (level) =>
            props.need.inheritedLevel &&
            levels.indexOf(level) < levels.indexOf(props.need.inheritedLevel),
    ),
);
const canRequest = computed(
    () =>
        props.canWrite &&
        props.need.target.active &&
        props.need.directLevel &&
        downgradeLevels.value.includes(props.need.directLevel) &&
        !props.need.downgrade,
);
const fieldLabels = {
    ratings: 'Bewertung',
    [`ratings.${props.need.objective}.level`]: 'Schutzbedarf',
    [`ratings.${props.need.objective}.rationale`]: 'Begründung',
    expected_versions: 'Bearbeitungsstand',
    lock_version: 'Bearbeitungsstand',
    status: 'Freigabe',
    downgrade: 'Herabstufung',
    requested_level: 'Beantragter Wert',
    rationale: 'Begründung',
};
function rate(): void {
    if (props.need.actions.rate) {
        ratingForm.put(props.need.actions.rate, {
            preserveScroll: true,
            errorBag: 'protectionRate',
        });
    }
}
function approve(): void {
    if (props.need.actions.approve) {
        approvalForm.post(props.need.actions.approve, {
            preserveScroll: true,
            errorBag: 'protectionApprove',
        });
    }
}
function requestDowngrade(): void {
    if (props.need.actions.requestDowngrade) {
        requestForm.post(props.need.actions.requestDowngrade, {
            preserveScroll: true,
            errorBag: 'protectionDowngradeRequest',
            onSuccess: () => requestForm.reset('rationale'),
        });
    }
}
</script>

<template>
    <section
        v-if="canWrite && need.target.active"
        class="mt-6 space-y-5"
        aria-label="Schutzbedarf bearbeiten"
    >
        <form
            class="rounded-xl border border-slate-700 p-4"
            @submit.prevent="rate"
        >
            <h3 class="font-semibold">
                {{ objectiveLabels[need.objective] }} direkt bewerten
            </h3>
            <div class="mt-3 grid gap-3 md:grid-cols-2">
                <label class="text-sm"
                    >Direkter Schutzbedarf
                    <select
                        v-model="ratingForm.ratings[need.objective]!.level"
                        required
                        class="mt-1 block w-full rounded border border-slate-600 bg-slate-950 px-3 py-2"
                    >
                        <option
                            v-for="level in levels"
                            :key="level"
                            :value="level"
                        >
                            {{ levelLabels[level] }}
                        </option>
                    </select>
                </label>
                <label class="text-sm"
                    >Begründung
                    <textarea
                        v-model="ratingForm.ratings[need.objective]!.rationale"
                        required
                        maxlength="10000"
                        class="mt-1 block w-full rounded border border-slate-600 bg-slate-950 px-3 py-2"
                    />
                </label>
            </div>
            <FormErrorList
                :errors="ratingForm.errors"
                :labels="fieldLabels"
                class="mt-3"
            />
            <p v-if="ratingConflict" class="mt-2 text-amber-200" role="alert">
                Der Schutzbedarf hat sich geändert. Bitte laden Sie die
                aktuellen Werte neu.
                <button
                    type="button"
                    class="underline"
                    @click="router.reload()"
                >
                    Neu laden
                </button>
            </p>
            <button
                type="submit"
                :disabled="ratingForm.processing"
                class="mt-3 rounded bg-cyan-500 px-4 py-2 font-semibold text-slate-950 disabled:opacity-50"
            >
                Bewertung speichern
            </button>
        </form>

        <form
            class="rounded-xl border border-slate-700 p-4"
            @submit.prevent="approve"
        >
            <h3 class="font-semibold">Zielbewertung freigeben</h3>
            <p class="mt-1 text-sm text-slate-400">
                Die Freigabe gilt für alle drei Schutzziele dieses Ziels. Eine
                beantragte Herabstufung bleibt bis zur Entscheidung unwirksam.
            </p>
            <FormErrorList
                :errors="approvalForm.errors"
                :labels="fieldLabels"
                class="mt-3"
            />
            <p v-if="approvalConflict" class="mt-2 text-amber-200" role="alert">
                Der Schutzbedarf hat sich geändert.
                <button
                    type="button"
                    class="underline"
                    @click="router.reload()"
                >
                    Neu laden
                </button>
            </p>
            <button
                type="submit"
                :disabled="approvalForm.processing"
                class="mt-3 rounded bg-emerald-500 px-4 py-2 font-semibold text-slate-950 disabled:opacity-50"
            >
                Ziel freigeben
            </button>
        </form>

        <form
            v-if="canRequest"
            class="rounded-xl border border-amber-500/40 p-4"
            @submit.prevent="requestDowngrade"
        >
            <h3 class="font-semibold">Herabstufung beantragen</h3>
            <p class="mt-1 text-sm text-amber-200">
                Der beantragte Wert wird erst nach einer zweiten Entscheidung
                wirksam.
            </p>
            <p class="mt-3 text-sm">
                Beantragter Wert:
                {{ levelLabels[requestForm.requested_level] }}. Für einen
                anderen Wert zuerst die direkte Bewertung speichern.
            </p>
            <label class="mt-3 block text-sm"
                >Begründung
                <textarea
                    v-model="requestForm.rationale"
                    required
                    maxlength="10000"
                    class="mt-1 block w-full rounded border border-slate-600 bg-slate-950 px-3 py-2"
                />
            </label>
            <FormErrorList
                :errors="requestForm.errors"
                :labels="fieldLabels"
                class="mt-3"
            />
            <button
                type="submit"
                :disabled="requestForm.processing"
                class="mt-3 rounded bg-amber-400 px-4 py-2 font-semibold text-slate-950 disabled:opacity-50"
            >
                Antrag senden
            </button>
        </form>
    </section>
</template>
