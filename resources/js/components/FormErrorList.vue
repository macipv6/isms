<script setup lang="ts">
defineProps<{
    errors: Record<string, string>;
    labels: Record<string, string>;
    title?: string;
}>();
</script>

<template>
    <div
        v-if="Object.keys(errors).length"
        class="rounded-lg border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-100"
        role="alert"
    >
        <p class="font-semibold">
            {{ title ?? 'Bitte prüfen Sie Ihre Angaben.' }}
        </p>
        <ul class="mt-2 list-disc space-y-1 pl-5">
            <li v-for="(message, field) in errors" :key="field">
                <strong>{{ labels[field] ?? 'Formular' }}:</strong>
                {{ message }}
            </li>
        </ul>
    </div>
</template>
