<script setup lang="ts">
import { levelLabels, type PropagationSource } from '@/types/risk';

defineProps<{ sources: PropagationSource[] }>();
</script>

<template>
    <section aria-label="Herkunft des geerbten Schutzbedarfs">
        <h4 class="font-semibold">Maximale Herkunft und Abhängigkeitspfad</h4>
        <p v-if="sources.length === 0" class="mt-1 text-slate-400">
            Kein geerbter Schutzbedarf.
        </p>
        <ol v-else class="mt-2 list-decimal space-y-2 pl-5">
            <li
                v-for="source in sources"
                :key="`${source.source.type}-${source.source.id}`"
            >
                <span class="font-medium"
                    >{{ source.source.key }} · {{ source.source.name }}</span
                >
                <span class="text-slate-400">
                    · {{ levelLabels[source.level] }}</span
                >
                <span class="block text-slate-400">
                    Pfad, erster Abschnitt: {{ source.path.join(' → ') }}
                </span>
            </li>
        </ol>
    </section>
</template>
