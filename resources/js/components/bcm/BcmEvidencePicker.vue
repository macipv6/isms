<script setup lang="ts">
import { ref } from 'vue';
const ids = defineModel<string[]>({ required: true });
const props = defineProps<{ base: string; readonly?: boolean }>();
const options = ref<{ id: string; label: string; status: string }[]>([]);
const page = ref(1);
const last = ref(1);
const loading = ref(false);
const error = ref('');
async function load(number = 1): Promise<void> {
    if (props.readonly || loading.value) return;
    loading.value = true;
    error.value = '';
    try {
        const response = await fetch(
            `${props.base}/risks?reference_page=${number}`,
            {
                headers: {
                    'X-Inertia': 'true',
                    'X-Inertia-Partial-Component': 'risks/Index',
                    'X-Inertia-Partial-Data': 'resources,referencePages',
                    Accept: 'application/json',
                },
                credentials: 'same-origin',
            },
        );
        if (!response.ok)
            throw new Error('Nachweise konnten nicht geladen werden.');
        const data = await response.json();
        options.value = data.props.resources.evidence;
        page.value = data.props.referencePages.resource.current;
        last.value = data.props.referencePages.resource.last;
    } catch {
        error.value =
            'Nachweise konnten nicht geladen werden. Bitte erneut versuchen.';
    } finally {
        loading.value = false;
    }
}
</script>
<template>
    <section class="mt-4">
        <h3 class="font-semibold">Nachweise</h3>
        <p class="text-sm text-slate-400">
            Für Freigabe und Abschluss prüft der Server die Verifikation erneut.
        </p>
        <p v-for="id in ids" :key="id" class="mt-2">
            <a :href="`${base}/evidence/${id}/download`" class="text-cyan-300"
                >{{
                    options.find((item) => item.id === id)?.label ??
                    'Verknüpfter Nachweis'
                }}
                herunterladen</a
            ><button
                v-if="!readonly"
                type="button"
                class="ml-3 text-cyan-300"
                @click="ids.splice(ids.indexOf(id), 1)"
            >
                Entfernen
            </button>
        </p>
        <template v-if="!readonly"
            ><button
                type="button"
                :disabled="loading"
                class="mt-2 text-cyan-300"
                @click="load()"
            >
                Projekt-Nachweise auswählen
            </button>
            <p v-if="error" role="alert">{{ error }}</p>
            <label v-for="option in options" :key="option.id" class="mt-2 block"
                ><input
                    v-model="ids"
                    type="checkbox"
                    :value="option.id"
                    :disabled="!ids.includes(option.id) && ids.length >= 200"
                />
                {{ option.label }} · {{ option.status }}</label
            >
            <nav
                v-if="options.length || page > 1"
                class="mt-2 flex gap-3"
                aria-label="Nachweis-Auswahlseiten"
            >
                <button
                    type="button"
                    :disabled="loading || page === 1"
                    @click="load(page - 1)"
                >
                    Zurück</button
                ><span>{{ page }} / {{ last }}</span
                ><button
                    type="button"
                    :disabled="loading || page >= last"
                    @click="load(page + 1)"
                >
                    Weiter
                </button>
            </nav></template
        >
    </section>
</template>
