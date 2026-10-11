<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import RiskFormFeedback from './RiskFormFeedback.vue';
import type { RiskResources } from '@/types/risk';
defineProps<{ url: string; resources: RiskResources; canWrite: boolean }>();
const form = useForm({
    action: 'reduce',
    rationale: '',
    counterparty_name: '',
    counterparty_email: '',
    cessation_statement: '',
    cessation_evidence_id: '',
});
</script>
<template>
    <form @submit.prevent="form.post(url, { preserveScroll: true })">
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
