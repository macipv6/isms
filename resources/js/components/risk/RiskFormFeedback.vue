<script setup lang="ts">
import { router } from '@inertiajs/vue3';
defineProps<{
    errors: Record<string, string>;
    success?: boolean;
    conflict?: boolean;
}>();
const emit = defineEmits<{ refreshed: [] }>();
function refresh(): void {
    router.reload({ onSuccess: () => emit('refreshed') });
}
</script>
<template>
    <div
        v-if="Object.keys(errors).length || conflict"
        role="alert"
        class="my-3 rounded border border-red-500 p-3 text-red-200"
    >
        <p v-for="(error, field) in errors" :key="field">
            {{ field }}: {{ error }}
        </p>
        <p v-if="conflict && !errors.cycle">
            Der Versionskonflikt besteht weiterhin. Bitte laden Sie den
            aktuellen Stand.
        </p>
        <button
            v-if="errors.cycle || conflict"
            type="button"
            class="underline"
            @click="refresh"
        >
            Aktuellen Stand laden
        </button>
    </div>
    <p v-if="success" role="status" class="my-3 text-emerald-300">
        Gespeichert.
    </p>
</template>
