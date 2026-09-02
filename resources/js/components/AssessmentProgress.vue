<script setup lang="ts">
import type { AssessmentProgressData } from '@/types/assessment';

defineProps<{
    progress: AssessmentProgressData;
}>();
</script>

<template>
    <section class="rounded-2xl border border-slate-800 bg-slate-900/60 p-5">
        <div class="flex items-end justify-between gap-4">
            <div>
                <p class="text-sm font-medium text-slate-300">
                    Gesamtfortschritt
                </p>
                <p class="mt-1 text-sm text-slate-500">
                    {{ progress.answered }} von {{ progress.total }} Fragen
                    beantwortet
                </p>
            </div>
            <p class="text-2xl font-semibold text-cyan-300">
                {{ progress.percentage }} %
            </p>
        </div>
        <div class="mt-4 h-2 overflow-hidden rounded-full bg-slate-800">
            <div
                class="h-full rounded-full bg-cyan-400 transition-all"
                :style="{ width: progress.percentage + '%' }"
            />
        </div>
    </section>
</template>
