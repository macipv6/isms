<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { ProjectWorkTab } from '@/types/work-items';

const props = defineProps<{
    organizationId: string;
    projectId: string;
    active: ProjectWorkTab;
}>();

const base = `/organizations/${props.organizationId}/projects/${props.projectId}`;
const tabs: Array<{ key: ProjectWorkTab; label: string; href: string }> = [
    { key: 'assessment', label: 'Bewertung', href: `${base}/assessment` },
    { key: 'evidence', label: 'Nachweise', href: `${base}/evidence` },
    { key: 'findings', label: 'Feststellungen', href: `${base}/findings` },
    { key: 'measures', label: 'Maßnahmen', href: `${base}/measures` },
];
</script>

<template>
    <nav
        class="mt-7 flex gap-2 overflow-x-auto border-b border-slate-800"
        aria-label="Projektbereiche"
    >
        <Link
            v-for="tab in tabs"
            :key="tab.key"
            :href="tab.href"
            :aria-current="active === tab.key ? 'page' : undefined"
            :class="
                active === tab.key
                    ? 'border-cyan-400 text-cyan-200'
                    : 'border-transparent text-slate-400 hover:border-slate-600 hover:text-slate-200'
            "
            class="border-b-2 px-4 py-3 text-sm font-medium whitespace-nowrap"
        >
            {{ tab.label }}
        </Link>
    </nav>
</template>
