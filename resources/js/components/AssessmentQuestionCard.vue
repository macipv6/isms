<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import type {
    AnswerValue,
    AssessmentQuestion,
    ComplianceStatus,
} from '@/types/assessment';

const props = defineProps<{
    organizationId: string;
    projectId: string;
    question: AssessmentQuestion;
    canAnswer: boolean;
}>();

const form = useForm<{
    answer: AnswerValue;
    compliance_status: ComplianceStatus | '';
    comment: string;
}>({
    answer: props.question.answer,
    compliance_status: props.question.compliance_status ?? '',
    comment: props.question.comment ?? '',
});

const statusLabels: Record<ComplianceStatus, string> = {
    fulfilled: 'Erfüllt',
    partial: 'Teilweise erfüllt',
    not_fulfilled: 'Nicht erfüllt',
    not_applicable: 'Nicht anwendbar',
};

function setTextAnswer(event: Event): void {
    form.answer = (event.target as HTMLInputElement).value;
}

function setNumberAnswer(event: Event): void {
    const value = (event.target as HTMLInputElement).value;
    form.answer = value === '' ? null : Number(value);
}

function toggleOption(value: string, event: Event): void {
    const checked = (event.target as HTMLInputElement).checked;
    const selected = Array.isArray(form.answer) ? [...form.answer] : [];

    form.answer = checked
        ? [...selected.filter((item) => item !== value), value]
        : selected.filter((item) => item !== value);
}

function submit(): void {
    form.put(
        '/organizations/' +
            props.organizationId +
            '/projects/' +
            props.projectId +
            '/assessment/questions/' +
            props.question.id,
        { preserveScroll: true },
    );
}
</script>

<template>
    <article class="rounded-2xl border border-slate-800 bg-slate-900/50 p-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="max-w-3xl">
                <p class="text-xs font-medium tracking-wide text-slate-500">
                    {{ question.question_key }}
                </p>
                <h3 class="mt-2 text-lg font-semibold">{{ question.title }}</h3>
                <p class="mt-2 text-slate-300">{{ question.question_text }}</p>
            </div>
            <div class="flex gap-2 text-xs">
                <span
                    class="rounded-full bg-slate-800 px-3 py-1 text-slate-300"
                    >{{ question.severity }}</span
                >
                <span
                    v-if="question.evidence_expected"
                    class="rounded-full bg-amber-400/10 px-3 py-1 text-amber-200"
                    >Nachweis erwartet</span
                >
            </div>
        </div>

        <p
            v-if="question.help_text"
            class="mt-4 rounded-lg bg-slate-950/70 p-3 text-sm text-slate-400"
        >
            {{ question.help_text }}
        </p>

        <form class="mt-5 space-y-5" @submit.prevent="submit">
            <fieldset
                v-if="question.answer_type === 'boolean'"
                class="flex gap-3"
            >
                <legend class="sr-only">Antwort</legend>
                <label
                    v-for="choice in [
                        { label: 'Ja', value: true },
                        { label: 'Nein', value: false },
                    ]"
                    :key="choice.label"
                    class="flex items-center gap-2 rounded-lg border border-slate-700 px-4 py-2"
                >
                    <input
                        type="radio"
                        :name="'answer-' + question.id"
                        :checked="form.answer === choice.value"
                        :disabled="!canAnswer"
                        @change="form.answer = choice.value"
                    />
                    {{ choice.label }}
                </label>
            </fieldset>

            <select
                v-else-if="question.answer_type === 'single_choice'"
                v-model="form.answer"
                :disabled="!canAnswer"
                class="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2"
            >
                <option :value="null">Bitte auswählen</option>
                <option
                    v-for="option in question.options"
                    :key="option.value"
                    :value="option.value"
                >
                    {{ option.label }}
                </option>
            </select>

            <fieldset
                v-else-if="question.answer_type === 'multiple_choice'"
                class="grid gap-2 sm:grid-cols-2"
            >
                <legend class="sr-only">Mehrfachauswahl</legend>
                <label
                    v-for="option in question.options"
                    :key="option.value"
                    class="flex items-center gap-2 rounded-lg border border-slate-700 px-4 py-2"
                >
                    <input
                        type="checkbox"
                        :checked="
                            Array.isArray(form.answer) &&
                            form.answer.includes(option.value)
                        "
                        :disabled="!canAnswer"
                        @change="toggleOption(option.value, $event)"
                    />
                    {{ option.label }}
                </label>
            </fieldset>

            <textarea
                v-else-if="question.answer_type === 'text'"
                :value="typeof form.answer === 'string' ? form.answer : ''"
                :disabled="!canAnswer"
                rows="4"
                maxlength="10000"
                class="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2"
                @input="setTextAnswer"
            />

            <input
                v-else
                type="number"
                step="any"
                :value="typeof form.answer === 'number' ? form.answer : ''"
                :disabled="!canAnswer"
                class="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2"
                @input="setNumberAnswer"
            />

            <div class="grid gap-4 md:grid-cols-2">
                <label>
                    <span class="text-sm font-medium text-slate-300"
                        >Bewertung</span
                    >
                    <select
                        v-model="form.compliance_status"
                        required
                        :disabled="!canAnswer"
                        class="mt-2 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2"
                    >
                        <option value="">Bitte auswählen</option>
                        <option
                            v-for="(label, value) in statusLabels"
                            :key="value"
                            :value="value"
                        >
                            {{ label }}
                        </option>
                    </select>
                </label>
                <label>
                    <span class="text-sm font-medium text-slate-300"
                        >Kommentar</span
                    >
                    <textarea
                        v-model="form.comment"
                        rows="3"
                        maxlength="10000"
                        :disabled="!canAnswer"
                        class="mt-2 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2"
                    />
                </label>
            </div>

            <p v-if="form.errors.answer" class="text-sm text-red-300">
                {{ form.errors.answer }}
            </p>
            <p
                v-if="form.errors.compliance_status"
                class="text-sm text-red-300"
            >
                {{ form.errors.compliance_status }}
            </p>
            <p v-if="form.errors.comment" class="text-sm text-red-300">
                {{ form.errors.comment }}
            </p>
            <p v-if="form.recentlySuccessful" class="text-sm text-emerald-300">
                Antwort gespeichert.
            </p>
            <button
                v-if="canAnswer"
                type="submit"
                :disabled="form.processing"
                class="rounded-lg bg-cyan-500 px-5 py-2.5 font-semibold text-slate-950 disabled:opacity-50"
            >
                {{ form.processing ? 'Speichert …' : 'Antwort speichern' }}
            </button>
        </form>
    </article>
</template>
