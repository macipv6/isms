<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import RiskFormFeedback from './RiskFormFeedback.vue';
import type { RiskTarget, RiskThreat } from '@/types/risk';
const props = defineProps<{
    base: string;
    target: RiskTarget;
    threats: RiskThreat[];
    canWrite: boolean;
}>();
const selected = ref('');
const review = computed(() =>
    props.target.reviews.find((r) => r.threatId === selected.value),
);
const form = useForm({ status: 'applicable', rationale: '' });
const custom = useForm({
    code: '',
    title: '',
    description: '',
    addition_rationale: '',
    responsible_name: '',
    responsible_email: '',
    confidentiality: false,
    integrity: false,
    availability: true,
});
function selectReview(): void {
    form.clearErrors();
    form.status =
        review.value?.status === 'not_applicable'
            ? 'not_applicable'
            : 'applicable';
    form.rationale = review.value?.rationale ?? '';
}
</script>
<template>
    <section aria-label="Gefährdungsübersicht">
        <h3 class="my-3 text-lg font-semibold">Gefährdungsübersicht</h3>
        <table class="w-full text-left">
            <thead>
                <tr>
                    <th scope="col">Gefährdung</th>
                    <th scope="col">Prüfstatus</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="threat in threats" :key="threat.id">
                    <td>{{ threat.code }} · {{ threat.title }}</td>
                    <td>
                        {{
                            target.reviews.find((r) => r.threatId === threat.id)
                                ?.status ?? 'unreviewed'
                        }}
                    </td>
                </tr>
            </tbody>
        </table>
        <form
            @submit.prevent="
                review &&
                form.patch(`${base}/reviews/${review.id}`, {
                    preserveScroll: true,
                })
            "
        >
            <fieldset
                :disabled="!canWrite || form.processing"
                class="mt-3 grid gap-3 disabled:opacity-60"
            >
                <label
                    >Gefährdung einzeln prüfen
                    <select v-model="selected" @change="selectReview">
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
                    >Anwendbarkeit
                    <select v-model="form.status">
                        <option value="applicable">Anwendbar</option>
                        <option value="not_applicable">Nicht anwendbar</option>
                    </select></label
                >
                <label
                    >Begründung
                    <textarea
                        v-model="form.rationale"
                        :required="form.status === 'not_applicable'"
                        maxlength="10000"
                    /></label
                ><button type="submit" :disabled="!review">
                    Prüfung speichern
                </button>
            </fieldset>
            <RiskFormFeedback
                :errors="form.errors"
                :success="form.recentlySuccessful"
            />
        </form>
        <details>
            <summary class="my-3 cursor-pointer">
                Eigene Gefährdung ergänzen
            </summary>
            <form
                @submit.prevent="
                    custom.post(`${base}/threats`, { preserveScroll: true })
                "
            >
                <fieldset
                    :disabled="!canWrite || custom.processing"
                    class="grid gap-3 disabled:opacity-60"
                >
                    <label
                        >Code
                        <input
                            v-model="custom.code"
                            required
                            maxlength="32" /></label
                    ><label
                        >Titel
                        <input
                            v-model="custom.title"
                            required
                            maxlength="255" /></label
                    ><label
                        >Beschreibung
                        <textarea
                            v-model="custom.description"
                            required
                            maxlength="10000"
                        /></label
                    ><label
                        >Ergänzungsgrund
                        <textarea
                            v-model="custom.addition_rationale"
                            required
                            maxlength="10000"
                        /></label
                    ><label
                        >Verantwortlich
                        <input
                            v-model="custom.responsible_name"
                            required
                            maxlength="160" /></label
                    ><label
                        >E-Mail
                        <input v-model="custom.responsible_email" type="email"
                    /></label>
                    <label
                        ><input
                            v-model="custom.confidentiality"
                            type="checkbox"
                        />
                        Vertraulichkeit</label
                    ><label
                        ><input v-model="custom.integrity" type="checkbox" />
                        Integrität</label
                    ><label
                        ><input v-model="custom.availability" type="checkbox" />
                        Verfügbarkeit</label
                    ><button type="submit">Gefährdung anlegen</button>
                </fieldset>
                <RiskFormFeedback
                    :errors="custom.errors"
                    :success="custom.recentlySuccessful"
                />
            </form>
        </details>
    </section>
</template>
