<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import RiskFormFeedback from './RiskFormFeedback.vue';
import { useAssessmentVersion } from './useAssessmentVersion';
import type { MethodologyVersion, RiskCycle } from '@/types/risk';
const props = defineProps<{
    url: string;
    cycle: RiskCycle;
    methodology: MethodologyVersion;
    phase: 'inherent' | 'residual';
    canWrite: boolean;
}>();
const form = useForm({
    phase: props.phase,
    frequency_key: '',
    impact_key: '',
    rationale: '',
    lock_version: props.cycle.lockVersion,
});
const version = useAssessmentVersion(() => props.cycle.lockVersion, form);
</script>
<template>
    <form
        @submit.prevent="
            form.post(url, { preserveScroll: true, onSuccess: version.saved })
        "
    >
        <h3 class="my-3 text-lg font-semibold">
            {{ phase === 'inherent' ? 'Inhärente Bewertung' : 'Restbewertung' }}
        </h3>
        <p v-if="phase === 'residual'" class="text-amber-200">
            Reduzieren: alle verknüpften Maßnahmen wirksam abgeschlossen.
            Übertragen: Vertragspartner und geprüfter Nachweis. Vermeiden:
            geprüfter Stilllegungsnachweis. Akzeptieren: keine Senkung der
            Ausgangswerte.
        </p>
        <fieldset
            :disabled="!canWrite || form.processing"
            class="grid gap-3 disabled:opacity-60"
        >
            <label
                >Häufigkeit
                <select v-model="form.frequency_key" required>
                    <option value="">Bitte auswählen</option>
                    <option
                        v-for="level in methodology.levels.frequency"
                        :key="level.key"
                        :value="level.key"
                    >
                        {{ level.label }}
                    </option>
                </select></label
            >
            <label
                >Auswirkung
                <select v-model="form.impact_key" required>
                    <option value="">Bitte auswählen</option>
                    <option
                        v-for="level in methodology.levels.impact"
                        :key="level.key"
                        :value="level.key"
                    >
                        {{ level.label }}
                    </option>
                </select></label
            >
            <label
                >Begründung
                <textarea v-model="form.rationale" required maxlength="10000" />
            </label>
            <button type="submit">Bewertung speichern</button>
        </fieldset>
        <p class="text-sm text-slate-400">
            Die Kategorie wird nach dem Speichern vom Server aus der fixierten
            Matrix abgeleitet.
        </p>
        <RiskFormFeedback
            :errors="form.errors"
            :success="form.recentlySuccessful"
            @refreshed="version.refreshed"
        />
    </form>
</template>
