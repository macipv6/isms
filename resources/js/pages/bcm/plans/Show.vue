<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import ContinuityPlanForm from '@/components/bcm/ContinuityPlanForm.vue';
import BcmSourceHistory from '@/components/bcm/BcmSourceHistory.vue';
import FormErrorList from '@/components/FormErrorList.vue';
import ProjectWorkNavigation from '@/components/ProjectWorkNavigation.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { bcmBase, type PlanPage, type PlanRevision } from '@/types/bcm';
const props = defineProps<
    Omit<PlanPage, 'revision'> & { revision: PlanRevision }
>();
const base = bcmBase(props.project.id);
const revisionBase = `${base}/plans/${props.revision.id}`;
const workBase = `/organizations/${props.project.organization_id}/projects/${props.project.id}`;
const revise = useForm({});
function successor(): void {
    if (
        props.capabilities.revise &&
        props.revision.status === 'approved' &&
        !revise.processing
    )
        revise.post(`${revisionBase}/revise`);
}
</script>
<template>
    <Head
        :title="(revision.title || 'Notfallplan') + ' · v' + revision.version"
    /><AppLayout
        ><Link :href="`${base}/plans`" class="text-cyan-300"
            >← Notfallpläne</Link
        >
        <h1 class="mt-5 text-3xl font-semibold">
            {{ revision.title || 'Notfallplan' }} · v{{ revision.version }}
        </h1>
        <ProjectWorkNavigation
            :organization-id="project.organization_id"
            :project-id="project.id"
            active="plans"
        />
        <p class="mt-5">
            {{
                revision.status === 'approved'
                    ? 'Freigegeben · unveränderliche historische Revision'
                    : 'Entwurf'
            }}
            {{ revision.approved_at }}
        </p>
        <p
            v-if="revision.status === 'approved' && !revision.freshness.current"
            class="mt-2 text-amber-200"
        >
            Erneute Prüfung erforderlich. Die historische Freigabe bleibt
            erhalten.
        </p>
        <p v-if="!revision.lists_complete" class="mt-2 text-amber-200">
            Die gespeicherten Listen überschreiten die Bearbeitungsgrenze;
            dieser Plan ist schreibgeschützt.
        </p>
        <p v-if="!capabilities.write" class="mt-2 text-amber-200">
            Schreibgeschützt.
        </p>
        <button
            v-if="capabilities.revise"
            type="button"
            :disabled="revise.processing"
            class="mt-3 text-cyan-300"
            @click="successor"
        >
            Neue Planrevision anlegen</button
        ><FormErrorList :errors="revise.errors" :labels="{}" />
        <ContinuityPlanForm
            :key="`${revision.id}-${revision.status}`"
            :revision="revision"
            :base="revisionBase"
            :can-edit="!!capabilities.edit"
            :can-approve="!!capabilities.approve"
            :process-options="process_options"
            :risk-options="risk_options"
            :linked-processes="linked_processes"
            :linked-risks="linked_risks"
            :evidence-base="workBase"
        /><BcmSourceHistory
            :base="base"
            :sources="sources"
            :history="history"
            kind="plans"
        />
    </AppLayout>
</template>
