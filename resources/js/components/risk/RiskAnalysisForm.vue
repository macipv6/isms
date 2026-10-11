<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import RiskFormFeedback from './RiskFormFeedback.vue';
import type { RiskThreat, RiskTrigger } from '@/types/risk';
const props = defineProps<{
    url: string;
    threats: RiskThreat[];
    triggers: RiskTrigger[];
    canWrite: boolean;
}>();
const form = useForm({
    threat_id: '',
    title: '',
    cause: '',
    event: '',
    impact: '',
    confidentiality: false,
    integrity: false,
    availability: true,
    trigger_ids: [] as string[],
});
</script>
<template>
    <form @submit.prevent="form.post(props.url, { preserveScroll: true })">
        <h3 class="my-3 text-lg font-semibold">Neues Szenario</h3>
        <fieldset
            :disabled="!canWrite || form.processing"
            class="grid gap-3 disabled:opacity-60"
        >
            <label
                >Gefährdung
                <select v-model="form.threat_id" required>
                    <option value="">Bitte auswählen</option>
                    <option
                        v-for="threat in threats"
                        :key="threat.id"
                        :value="threat.id"
                    >
                        {{ threat.code }} · {{ threat.title }}
                    </option>
                </select></label
            >
            <label
                >Titel <input v-model="form.title" required maxlength="255"
            /></label>
            <label
                >Ursache
                <textarea v-model="form.cause" required maxlength="10000" />
            </label>
            <label
                >Ereignis
                <textarea v-model="form.event" required maxlength="10000" />
            </label>
            <label
                >Auswirkung
                <textarea v-model="form.impact" required maxlength="10000" />
            </label>
            <label
                ><input v-model="form.confidentiality" type="checkbox" />
                Vertraulichkeit</label
            ><label
                ><input v-model="form.integrity" type="checkbox" />
                Integrität</label
            ><label
                ><input v-model="form.availability" type="checkbox" />
                Verfügbarkeit</label
            >
            <label
                v-for="trigger in triggers.filter((t) => t.status === 'open')"
                :key="trigger.id"
                ><input
                    v-model="form.trigger_ids"
                    type="checkbox"
                    :value="trigger.id"
                />
                Auslöser: {{ trigger.type }} · {{ trigger.rationale }}</label
            >
            <button type="submit">Szenario anlegen</button>
        </fieldset>
        <RiskFormFeedback
            :errors="form.errors"
            :success="form.recentlySuccessful"
        />
    </form>
</template>
