<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import RiskFormFeedback from './RiskFormFeedback.vue';
import type { RiskTrigger } from '@/types/risk';
defineProps<{
    base: string;
    url: string;
    triggers: RiskTrigger[];
    canWrite: boolean;
}>();
const form = useForm({ type: 'manual', rationale: '' });
const dismiss = useForm({});
</script>
<template>
    <section aria-label="Auslöser">
        <h3 class="my-3 text-lg font-semibold">Auslöser</h3>
        <ul>
            <li v-for="trigger in triggers" :key="trigger.id" class="my-2">
                {{ trigger.type }} · {{ trigger.status }} ·
                {{ trigger.rationale }}
                <button
                    v-if="
                        canWrite &&
                        trigger.type === 'manual' &&
                        trigger.status === 'open'
                    "
                    type="button"
                    :disabled="dismiss.processing"
                    @click="
                        dismiss.post(`${base}/triggers/${trigger.id}/dismiss`, {
                            preserveScroll: true,
                        })
                    "
                >
                    Zurücknehmen
                </button>
            </li>
        </ul>
        <RiskFormFeedback :errors="dismiss.errors" />
        <form @submit.prevent="form.post(url, { preserveScroll: true })">
            <fieldset
                :disabled="!canWrite || form.processing"
                class="grid gap-3 disabled:opacity-60"
            >
                <label
                    >Auslöserart
                    <select v-model="form.type">
                        <option value="manual">Manuell</option>
                        <option value="nonstandard_scenario">
                            Nicht standardisiertes Szenario
                        </option>
                    </select></label
                >
                <label
                    >Begründung
                    <textarea
                        v-model="form.rationale"
                        required
                        maxlength="10000"
                    /></label
                ><button type="submit">Auslöser anlegen</button>
            </fieldset>
            <RiskFormFeedback
                :errors="form.errors"
                :success="form.recentlySuccessful"
            />
        </form>
    </section>
</template>
