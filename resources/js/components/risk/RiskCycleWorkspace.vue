<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import RiskAssessmentForm from './RiskAssessmentForm.vue';
import RiskTreatmentForm from './RiskTreatmentForm.vue';
import RiskTreatmentDetails from './RiskTreatmentDetails.vue';
import RiskDecisionForm from './RiskDecisionForm.vue';
import RiskFormFeedback from './RiskFormFeedback.vue';
import type {
    MethodologyVersion,
    RiskCycle,
    RiskResourceKind,
    RiskResources,
} from '@/types/risk';
const props = defineProps<{
    base: string;
    cycle: RiskCycle;
    methodology: MethodologyVersion;
    resources: RiskResources;
    canWrite: boolean;
}>();
const writable = computed(
    () =>
        props.canWrite &&
        props.cycle.current &&
        !props.cycle.needsReview &&
        !['completed', 'superseded'].includes(props.cycle.status),
);
const prefix = computed(
    () => `${props.base.replace(/\/risks$/, '')}/risk-cycles/${props.cycle.id}`,
);
const action = useForm({ lock_version: props.cycle.lockVersion });
watch(
    () => props.cycle.lockVersion,
    (version) => {
        action.lock_version = version;
    },
);
const links = useForm({});
const selected = ref<Record<RiskResourceKind, string>>({
    finding: '',
    measure: '',
    evidence: '',
});
const kinds: RiskResourceKind[] = ['finding', 'measure', 'evidence'];
const labels = {
    finding: 'Feststellung',
    measure: 'Maßnahme',
    evidence: 'Nachweis',
};
const blockers = computed(() => props.cycle.completionBlockers);
</script>
<template>
    <article class="my-5 rounded border border-slate-700 p-4">
        <h3 class="text-xl font-semibold">
            {{ cycle.title }} · Zyklus {{ cycle.cycleNumber }}
        </h3>
        <p>
            {{ cycle.status }} · Restrisiko:
            {{ cycle.residualLevel ?? 'nicht bewertet' }}
        </p>
        <p v-if="cycle.needsReview" role="alert" class="my-3 text-amber-200">
            Dieser Zyklus ist veraltet. Historie prüfen und einen neuen Zyklus
            beginnen.
        </p>
        <p v-for="assessment in cycle.assessments" :key="assessment.id">
            {{ assessment.phase }}: {{ assessment.category }} ·
            {{ assessment.rationale }}
        </p>
        <RiskAssessmentForm
            v-if="cycle.status === 'draft'"
            :key="`${cycle.id}-inherent`"
            :url="`${prefix}/assessments`"
            :cycle="cycle"
            :methodology="methodology"
            phase="inherent"
            :can-write="writable"
        />
        <RiskTreatmentDetails
            v-if="cycle.treatment"
            :treatment="cycle.treatment"
        />
        <RiskTreatmentForm
            v-if="
                ['inherent_assessed', 'treatment_in_progress'].includes(
                    cycle.status,
                )
            "
            :url="`${prefix}/treatment`"
            :resources="resources"
            :treatment="cycle.treatment"
            :can-write="writable"
        />
        <section v-for="kind in kinds" :key="kind" class="my-3">
            <h4>{{ labels[kind] }} verknüpfen</h4>
            <p v-for="id in cycle.links[kind]" :key="id">
                {{ resources[kind].find((r) => r.id === id)?.label ?? id }}
                <button
                    v-if="writable"
                    type="button"
                    :disabled="links.processing"
                    @click="
                        links.delete(`${prefix}/links/${kind}/${id}`, {
                            preserveScroll: true,
                        })
                    "
                >
                    Entfernen
                </button>
            </p>
            <label
                >{{ labels[kind] }}
                <select v-model="selected[kind]" :disabled="!writable">
                    <option value="">Bitte auswählen</option>
                    <option
                        v-for="resource in resources[kind]"
                        :key="resource.id"
                        :value="resource.id"
                    >
                        {{ resource.label }} · {{ resource.status }}
                    </option>
                </select></label
            ><button
                v-if="writable"
                type="button"
                :disabled="!selected[kind] || links.processing"
                @click="
                    links.post(`${prefix}/links/${kind}/${selected[kind]}`, {
                        preserveScroll: true,
                    })
                "
            >
                Verknüpfen
            </button>
        </section>
        <RiskFormFeedback :errors="links.errors" />
        <RiskAssessmentForm
            v-if="cycle.status === 'treatment_in_progress'"
            :key="`${cycle.id}-residual`"
            :url="`${prefix}/assessments`"
            :cycle="cycle"
            :methodology="methodology"
            phase="residual"
            :can-write="writable"
        />
        <RiskDecisionForm
            v-if="cycle.decisionRequired"
            :url="`${prefix}/decisions`"
            :cycle="cycle"
            :evidence="resources.evidence"
            :can-write="
                writable && (cycle.canDecideInternal || cycle.canDecideExternal)
            "
        />
        <div class="my-3">
            <h4>Abschlussvoraussetzungen</h4>
            <p v-for="reason in blockers" :key="reason" class="text-amber-200">
                {{ reason }}
            </p>
            <button
                v-if="
                    writable &&
                    ['residual_assessed', 'awaiting_decision'].includes(
                        cycle.status,
                    )
                "
                type="button"
                :disabled="blockers.length > 0 || action.processing"
                @click="
                    action.post(`${prefix}/complete`, { preserveScroll: true })
                "
            >
                Zyklus abschließen
            </button>
        </div>
        <button
            v-if="
                canWrite &&
                (cycle.status === 'completed' ||
                    (cycle.status === 'superseded' &&
                        cycle.needsReview &&
                        !cycle.current))
            "
            type="button"
            :disabled="action.processing"
            @click="
                action.post(`${base}/analyses/${cycle.analysisId}/cycles`, {
                    preserveScroll: true,
                })
            "
        >
            Neuen Zyklus beginnen
        </button>
        <RiskFormFeedback
            :errors="action.errors"
            :success="action.recentlySuccessful"
        />
    </article>
</template>
