<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { reactive, watch } from 'vue';
import FormErrorList from '@/components/FormErrorList.vue';
import type { BcmFinding } from '@/types/bcm';
const props = defineProps<{ finding: BcmFinding; base: string }>();
const decision = useForm<{
    status: 'accepted' | 'rejected';
    decision_note: string;
}>({ status: 'accepted', decision_note: '' });
const close = useForm({});
const measure = useForm({
    title: '',
    description: '',
    priority: 'medium',
    responsible_name: '',
    responsible_email: '',
    due_date: '',
});
const transitions = reactive(
    new Map<string, ReturnType<typeof transitionForm>>(),
);
function transitionForm() {
    return useForm({ status: 'in_progress', reason: '' });
}
watch(
    () => props.finding.measures.data,
    (rows) => {
        for (const row of rows)
            if (!transitions.has(row.id))
                transitions.set(row.id, transitionForm());
    },
    { immediate: true },
);
const statuses: Record<string, string> = {
    proposed: 'Vorgeschlagen',
    accepted: 'Akzeptiert',
    rejected: 'Verworfen',
    closed: 'Geschlossen',
};
function decide(status: 'accepted' | 'rejected'): void {
    if (!props.finding.capabilities.can_decide || decision.processing) return;
    decision.status = status;
    decision.patch(`${props.base}/findings/${props.finding.id}/decision`, {
        preserveScroll: true,
    });
}
function createMeasure(): void {
    if (!props.finding.capabilities.can_create_measure || measure.processing)
        return;
    measure.post(`${props.base}/findings/${props.finding.id}/measures`, {
        preserveScroll: true,
        onSuccess: () => measure.reset(),
    });
}
function closeFinding(): void {
    if (!props.finding.capabilities.can_close || close.processing) return;
    close.patch(`${props.base}/findings/${props.finding.id}/close`, {
        preserveScroll: true,
    });
}
function transition(id: string): void {
    const row = props.finding.measures.data.find((item) => item.id === id);
    const form = transitions.get(id);
    if (!row?.can_transition || !form || form.processing) return;
    form.patch(`${props.base}/measures/${id}/status`, { preserveScroll: true });
}
</script>
<template>
    <article class="mt-3 rounded border border-slate-700 p-3">
        <h4 class="font-semibold">{{ finding.title }}</h4>
        <p>
            Feststellungsstatus:
            {{ statuses[finding.status] ?? finding.status }} ·
            {{ finding.severity }}
        </p>
        <form
            v-if="finding.capabilities.can_decide"
            class="mt-3"
            @submit.prevent="decide('accepted')"
        >
            <label
                >Entscheidungsbegründung<textarea
                    v-model="decision.decision_note"
                    maxlength="10000"
                /></label
            ><button :disabled="decision.processing">
                Vorschlag akzeptieren</button
            ><button
                type="button"
                class="ml-3"
                :disabled="decision.processing"
                @click="decide('rejected')"
            >
                Begründet verwerfen</button
            ><FormErrorList :errors="decision.errors" :labels="{}" />
        </form>
        <form
            v-if="finding.capabilities.can_create_measure"
            class="mt-3"
            @submit.prevent="createMeasure"
        >
            <h5 class="font-medium">Maßnahme anlegen</h5>
            <label
                >Titel<input
                    v-model="measure.title"
                    required
                    maxlength="255" /></label
            ><label
                >Beschreibung<textarea
                    v-model="measure.description"
                    required
                    maxlength="10000"
                /></label
            ><label
                >Priorität<select v-model="measure.priority">
                    <option value="low">Niedrig</option>
                    <option value="medium">Mittel</option>
                    <option value="high">Hoch</option>
                    <option value="critical">Kritisch</option>
                </select></label
            ><label
                >Verantwortliche Person<input
                    v-model="measure.responsible_name"
                    required
                    maxlength="255" /></label
            ><label
                >E-Mail<input
                    v-model="measure.responsible_email"
                    type="email"
                    maxlength="255" /></label
            ><label
                >Fällig am<input
                    v-model="measure.due_date"
                    type="date"
                    required /></label
            ><button :disabled="measure.processing">Maßnahme anlegen</button
            ><FormErrorList :errors="measure.errors" :labels="{}" />
        </form>
        <h5 class="mt-3 font-medium">Maßnahmenstatus</h5>
        <p v-if="!finding.measures.total">Noch keine Maßnahmen.</p>
        <article
            v-for="row in finding.measures.data"
            :key="row.id"
            class="mt-2"
        >
            <p>{{ row.title }} · {{ row.status }}</p>
            <form
                v-if="row.can_transition && transitions.get(row.id)"
                @submit.prevent="transition(row.id)"
            >
                <label
                    >Status<select v-model="transitions.get(row.id)!.status">
                        <option value="planned">Geplant</option>
                        <option value="in_progress">In Bearbeitung</option>
                        <option value="blocked">Blockiert</option>
                        <option value="completed">Abgeschlossen</option>
                        <option value="cancelled">Abgebrochen</option>
                    </select></label
                ><label
                    >Begründung<textarea
                        v-model="transitions.get(row.id)!.reason"
                        maxlength="10000"
                    /></label
                ><button :disabled="transitions.get(row.id)!.processing">
                    Status ändern</button
                ><FormErrorList
                    :errors="transitions.get(row.id)!.errors"
                    :labels="{}"
                />
            </form>
        </article>
        <nav class="mt-3 flex gap-3" aria-label="Maßnahmenseiten">
            <Link
                v-if="finding.measures.prev_page_url"
                :href="finding.measures.prev_page_url"
                preserve-state
                preserve-scroll
                >Zurück</Link
            ><span
                >{{ finding.measures.current_page }} /
                {{ finding.measures.last_page }}</span
            ><Link
                v-if="finding.measures.next_page_url"
                :href="finding.measures.next_page_url"
                preserve-state
                preserve-scroll
                >Weiter</Link
            >
        </nav>
        <button
            v-if="finding.capabilities.can_close"
            type="button"
            class="mt-3"
            :disabled="close.processing"
            @click="closeFinding"
        >
            Feststellung schließen</button
        ><FormErrorList :errors="close.errors" :labels="{}" />
    </article>
</template>
<style scoped>
input,
textarea,
select {
    display: block;
    width: 100%;
    padding: 0.5rem;
    margin: 0.25rem 0 0.5rem;
    border: 1px solid #475569;
    border-radius: 0.25rem;
    background: #020617;
}
button {
    color: #67e8f9;
}
button:disabled {
    opacity: 0.4;
}
</style>
