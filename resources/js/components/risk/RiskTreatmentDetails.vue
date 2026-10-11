<script setup lang="ts">
import type { RiskTreatment } from '@/types/risk';
defineProps<{ treatment: RiskTreatment }>();
const actions: Record<string, string> = {
    reduce: 'Reduzieren',
    transfer: 'Übertragen',
    avoid: 'Vermeiden',
    accept: 'Akzeptieren',
};
</script>
<template>
    <section aria-label="Gespeicherte Behandlung" class="my-3">
        <h4 class="font-semibold">
            Gespeicherte Behandlung:
            {{ actions[treatment.action] ?? treatment.action }}
        </h4>
        <p>Begründung: {{ treatment.rationale }}</p>
        <p v-if="treatment.counterparty_name">
            Vertragspartner: {{ treatment.counterparty_name }}
        </p>
        <p v-if="treatment.counterparty_email">
            E-Mail: {{ treatment.counterparty_email }}
        </p>
        <p v-if="treatment.cessation_statement">
            Stilllegungserklärung: {{ treatment.cessation_statement }}
        </p>
        <p v-if="treatment.cessation_evidence_id">
            Stilllegungsnachweis: {{ treatment.cessation_evidence_id }}
        </p>
    </section>
</template>
