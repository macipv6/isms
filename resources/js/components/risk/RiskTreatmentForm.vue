<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import RiskFormFeedback from './RiskFormFeedback.vue';
import type { RiskResources, RiskTreatment } from '@/types/risk';
const props = defineProps<{
    url: string;
    resources: RiskResources;
    canWrite: boolean;
    treatment: RiskTreatment | null;
}>();
const form = useForm({
    action: props.treatment?.action ?? 'reduce',
    rationale: props.treatment?.rationale ?? '',
    counterparty_name: props.treatment?.counterparty_name ?? '',
    counterparty_email: props.treatment?.counterparty_email ?? '',
    cessation_statement: props.treatment?.cessation_statement ?? '',
    cessation_evidence_id: props.treatment?.cessation_evidence_id ?? '',
});
</script>
<template>
    <form
        v-if="canWrite"
        @submit.prevent="
            form.post(url, {
                preserveScroll: true,
                onSuccess: () => form.defaults(),
            })
        "
    >
        <h3 class="my-3 text-lg font-semibold">Behandlung</h3>
        <fieldset
            :disabled="!canWrite || form.processing"
            class="grid gap-3 disabled:opacity-60"
        >
            <label
                >Strategie
                <select v-model="form.action">
                    <option value="reduce">Reduzieren</option>
                    <option value="transfer">Übertragen</option>
                    <option value="avoid">Vermeiden</option>
                    <option value="accept">Akzeptieren</option>
                </select></label
            >
            <label
                >Begründung
                <textarea v-model="form.rationale" required maxlength="10000" />
            </label>
            <template v-if="form.action === 'transfer'"
                ><label
                    >Vertragspartner
                    <input
                        v-model="form.counterparty_name"
                        required
                        maxlength="255" /></label
                ><label
                    >E-Mail
                    <input
                        v-model="form.counterparty_email"
                        type="email"
                        maxlength="255" /></label
            ></template>
            <template v-if="form.action === 'avoid'"
                ><label
                    >Stilllegungserklärung
                    <textarea
                        v-model="form.cessation_statement"
                        required
                        maxlength="10000"
                    /></label
                ><label
                    >Stilllegungsnachweis
                    <select v-model="form.cessation_evidence_id" required>
                        <option value="">Bitte auswählen</option>
                        <option
                            v-if="
                                form.cessation_evidence_id &&
                                !resources.evidence.some(
                                    (e) => e.id === form.cessation_evidence_id,
                                )
                            "
                            :value="form.cessation_evidence_id"
                        >
                            Gespeicherter Nachweis
                            {{ form.cessation_evidence_id }}
                        </option>
                        <option
                            v-for="e in resources.evidence"
                            :key="e.id"
                            :value="e.id"
                        >
                            {{ e.label }} · {{ e.status }}
                        </option>
                    </select></label
                ></template
            >
            <button type="submit">Behandlung speichern</button>
        </fieldset>
        <RiskFormFeedback
            :errors="form.errors"
            :success="form.recentlySuccessful"
        />
    </form>
</template>
