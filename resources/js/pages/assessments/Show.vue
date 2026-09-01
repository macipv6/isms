<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AssessmentProgress from '@/components/AssessmentProgress.vue';
import AssessmentQuestionCard from '@/components/AssessmentQuestionCard.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type {
    AssessmentCategory,
    AssessmentProgressData,
} from '@/types/assessment';
import type { OrganizationReference } from '@/types/organization';

const props = defineProps<{
    organization: OrganizationReference;
    project: { id: string; name: string };
    catalogVersion: string;
    progress: AssessmentProgressData;
    categories: AssessmentCategory[];
    canAnswer: boolean;
}>();

const selectedKey = ref(props.categories[0]?.key ?? '');
const selectedCategory = computed(
    () =>
        props.categories.find(
            (category) => category.key === selectedKey.value,
        ) ??
        props.categories[0] ??
        null,
);

watch(
    () => props.categories,
    (categories) => {
        if (
            !categories.some((category) => category.key === selectedKey.value)
        ) {
            selectedKey.value = categories[0]?.key ?? '';
        }
    },
);
</script>

<template>
    <Head :title="project.name + ' · Bewertung'" />
    <AppLayout>
        <Link
            :href="'/organizations/' + organization.id"
            class="text-sm text-cyan-300 hover:text-cyan-200"
            >← Zum Kunden</Link
        >

        <div class="mt-5 flex flex-wrap items-end justify-between gap-4">
            <div>
                <p
                    class="text-sm font-semibold tracking-[0.2em] text-slate-400 uppercase"
                >
                    {{ organization.name }} · Katalog {{ catalogVersion }}
                </p>
                <h1 class="mt-2 text-3xl font-semibold">
                    {{ project.name }} · ISMS-Bewertung
                </h1>
                <p class="mt-3 text-slate-400">
                    Die Bewertung wird Frage für Frage gespeichert und kann
                    jederzeit fortgesetzt werden.
                </p>
            </div>
        </div>

        <AssessmentProgress :progress="progress" class="mt-8" />

        <div class="mt-8 grid gap-8 lg:grid-cols-[280px_1fr]">
            <nav class="space-y-2" aria-label="Themenbereiche">
                <button
                    v-for="category in progress.categories"
                    :key="category.key"
                    type="button"
                    :class="
                        selectedKey === category.key
                            ? 'border-cyan-500 bg-cyan-500/10 text-cyan-100'
                            : 'border-slate-800 bg-slate-900/40 text-slate-300 hover:bg-slate-900'
                    "
                    class="w-full rounded-xl border p-4 text-left"
                    @click="selectedKey = category.key"
                >
                    <span class="block font-medium">{{ category.name }}</span>
                    <span class="mt-1 block text-xs text-slate-500">
                        {{ category.answered }}/{{ category.total }} ·
                        {{ category.percentage }} %
                    </span>
                </button>
            </nav>

            <section v-if="selectedCategory">
                <h2 class="text-2xl font-semibold">
                    {{ selectedCategory.name }}
                </h2>
                <div class="mt-5 space-y-5">
                    <AssessmentQuestionCard
                        v-for="question in selectedCategory.questions"
                        :key="question.id"
                        :organization-id="organization.id"
                        :project-id="project.id"
                        :question="question"
                        :can-answer="canAnswer"
                    />
                </div>
            </section>
        </div>
    </AppLayout>
</template>
