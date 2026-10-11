<script setup lang="ts">
import { router } from '@inertiajs/vue3';
defineProps<{ errors: Record<string, string>; success?: boolean }>();
</script>
<template>
    <div
        v-if="Object.keys(errors).length"
        role="alert"
        class="my-3 rounded border border-red-500 p-3 text-red-200"
    >
        <p v-for="(error, field) in errors" :key="field">
            {{ field }}: {{ error }}
        </p>
        <button
            v-if="errors.cycle"
            type="button"
            class="underline"
            @click="router.reload()"
        >
            Aktuellen Stand laden
        </button>
    </div>
    <p v-if="success" role="status" class="my-3 text-emerald-300">
        Gespeichert.
    </p>
</template>
