<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import RiskFormFeedback from './RiskFormFeedback.vue';
import type { RiskCycle, RiskResource } from '@/types/risk';
const props = defineProps<{
    url: string;
    cycle: RiskCycle;
    evidence: RiskResource[];
    canWrite: boolean;
}>();
const form = useForm({
    channel: props.cycle.canDecideInternal ? 'internal' : 'external',
    status: 'accepted',
    rationale: '',
    decision_date: '',
    name: '',
    function: '',
    evidence_id: '',
});
</script>
<template>
    <form @submit.prevent="form.post(url, { preserveScroll: true })">
        <h3 class="my-3 text-lg font-semibold">Managemententscheidung</h3>
        <p>
            Hohe und sehr hohe Restrisiken erfordern eine aktuelle Akzeptanz.
            Die bewertende Person kann intern nicht selbst entscheiden.
        </p>
        <fieldset
            :disabled="!canWrite || form.processing"
            class="grid gap-3 disabled:opacity-60"
        >
            <label
                >Entscheidungsweg
                <select v-model="form.channel">
                    <option v-if="cycle.canDecideInternal" value="internal">
                        Intern
                    </option>
                    <option v-if="cycle.canDecideExternal" value="external">
                        Extern dokumentiert
                    </option>
                </select></label
            >
            <label
                >Entscheidung
                <select v-model="form.status">
                    <option value="accepted">Akzeptiert</option>
                    <option value="rejected">Abgelehnt</option>
                </select></label
            >
            <label
                >Datum
                <input
                    v-model="form.decision_date"
                    type="date"
                    required /></label
            ><label
                >Begründung
                <textarea v-model="form.rationale" required maxlength="10000" />
            </label>
            <template v-if="form.channel === 'external'"
                ><label
                    >Name
                    <input
                        v-model="form.name"
                        required
                        maxlength="255" /></label
                ><label
                    >Funktion
                    <input
                        v-model="form.function"
                        required
                        maxlength="255" /></label
                ><label
                    >Nachweis
                    <select v-model="form.evidence_id">
                        <option value="">Kein Nachweis</option>
                        <option v-for="e in evidence" :key="e.id" :value="e.id">
                            {{ e.label }} · {{ e.status }}
                        </option>
                    </select></label
                ></template
            >
            <button type="submit">Entscheidung dokumentieren</button>
        </fieldset>
        <RiskFormFeedback
            :errors="form.errors"
            :success="form.recentlySuccessful"
        />
    </form>
</template>
