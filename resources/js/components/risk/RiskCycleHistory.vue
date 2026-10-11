<script setup lang="ts">
import type { RiskCycle } from '@/types/risk';
import RiskTreatmentDetails from './RiskTreatmentDetails.vue';
defineProps<{ cycles: RiskCycle[] }>();
</script>
<template>
    <section aria-label="Unveränderliche Zyklushistorie">
        <h3 class="my-3 text-lg font-semibold">Zyklushistorie</h3>
        <details
            v-for="cycle in cycles"
            :key="cycle.id"
            class="my-3 rounded border border-slate-700 p-3"
        >
            <summary>
                Zyklus {{ cycle.cycleNumber }} · {{ cycle.title }} ·
                {{ cycle.status }}
                {{ cycle.needsReview ? '· Erneute Prüfung erforderlich' : '' }}
            </summary>
            <RiskTreatmentDetails
                v-if="cycle.treatment"
                :treatment="cycle.treatment"
            />
            <p>Ursache: {{ cycle.cause }}</p>
            <p>Ereignis: {{ cycle.event }}</p>
            <p>Auswirkung: {{ cycle.impact }}</p>
            <p v-for="assessment in cycle.assessments" :key="assessment.id">
                {{ assessment.phase }} · Revision {{ assessment.revision }} ·
                {{ assessment.category }} · Methodik
                {{ assessment.methodologyVersionId }} ·
                {{ assessment.rationale }}
            </p>
            <p v-for="decision in cycle.decisions" :key="decision.id">
                {{ decision.date }} · {{ decision.channel }} ·
                {{ decision.status }}
                {{ decision.invalidated ? '(ungültig)' : '' }} ·
                {{ decision.name }} {{ decision.function }} ·
                {{ decision.rationale }}
            </p>
        </details>
    </section>
</template>
