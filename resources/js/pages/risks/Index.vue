<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import ProjectWorkNavigation from '@/components/ProjectWorkNavigation.vue';
import RiskAnalysisForm from '@/components/risk/RiskAnalysisForm.vue';
import RiskCycleHistory from '@/components/risk/RiskCycleHistory.vue';
import RiskCycleWorkspace from '@/components/risk/RiskCycleWorkspace.vue';
import RiskFormFeedback from '@/components/risk/RiskFormFeedback.vue';
import RiskTriggerList from '@/components/risk/RiskTriggerList.vue';
import ThreatOverview from '@/components/risk/ThreatOverview.vue';
import type { RiskWorkspacePage } from '@/types/risk';
const props = defineProps<RiskWorkspacePage>();
const base = `/organizations/${props.organization.id}/projects/${props.project.id}/risks`;
const page = usePage<{ flash: { success?: string; error?: string } }>();
const selectedId = ref<string | null>(null);
const selected = computed(() =>
    props.targets.find((t) => `${t.type}:${t.id}` === selectedId.value),
);
const targetCycles = computed(() =>
    props.cycles.data.filter(
        (c) =>
            c.targetId === selected.value?.id &&
            c.targetType === selected.value?.type,
    ),
);
const targetTriggers = computed(() =>
    props.triggers.filter(
        (t) =>
            t.targetId === selected.value?.id &&
            t.targetType === selected.value?.type,
    ),
);
const methodology = computed(() =>
    props.methodologies.find(
        (v) => v.id === props.context?.methodologyVersionId,
    ),
);
const catalog = computed(() =>
    props.catalogs.find((v) => v.id === props.context?.catalogVersionId),
);
const filter = useForm({
    target_type: props.filters.target_type ?? '',
    threat: props.filters.threat ?? '',
    category: props.filters.category ?? '',
    status: props.filters.status ?? '',
    stale: props.filters.stale ?? '',
    decision_required: props.filters.decision_required ?? '',
});
const contextForm = useForm({
    methodology_version_id: props.context?.methodologyVersionId ?? '',
    catalog_version_id: props.context?.catalogVersionId ?? '',
});
const preview = ref(false);
const confirmed = ref(false);
function saveContext(): void {
    contextForm.post(
        props.context
            ? `${base}/context/${props.context.id}/migrate`
            : `${base}/context`,
        {
            preserveScroll: true,
            onSuccess: () => {
                preview.value = false;
                confirmed.value = false;
            },
        },
    );
}
const targetUrl = computed(() =>
    selected.value
        ? `${base}/targets/${selected.value.type}/${selected.value.id}`
        : '',
);
function referenceUrl(
    kind: 'target_page' | 'reference_page',
    value: number,
): string {
    const query = new URLSearchParams();
    for (const [key, entry] of Object.entries(props.filters))
        if (entry != null && entry !== '') query.set(key, String(entry));
    query.set(kind, String(value));
    return `${base}?${query.toString()}`;
}
</script>
<template>
    <Head :title="`${project.name} · Risiken`" />
    <AppLayout>
        <Link :href="`/organizations/${organization.id}`" class="text-cyan-300"
            >← Zum Kunden</Link
        >
        <h1 class="mt-5 text-3xl font-semibold">
            {{ project.name }} · Risiken
        </h1>
        <ProjectWorkNavigation
            :organization-id="organization.id"
            :project-id="project.id"
            active="risks"
        />
        <p v-if="!capabilities.write" class="my-4 text-amber-200">
            Dieses Projekt ist schreibgeschützt. Bewertungen und Historie
            bleiben einsehbar.
        </p>
        <p
            v-if="page.props.flash.success"
            role="status"
            class="my-3 text-emerald-300"
        >
            {{ page.props.flash.success }}
        </p>
        <p v-if="page.props.flash.error" role="alert" class="my-3 text-red-200">
            {{ page.props.flash.error }}
        </p>
        <section
            class="my-6 rounded border border-slate-700 p-4"
            aria-label="Fixierte Versionen"
        >
            <h2 class="text-xl font-semibold">Risikokontext</h2>
            <p v-if="context">
                Methodik: {{ methodology?.name }} v{{ methodology?.version }} ·
                {{ methodology?.publishedAt }}<br />Katalog:
                {{ catalog?.name }} v{{ catalog?.version }} ·
                {{ catalog?.publishedAt }}
            </p>
            <p v-else>
                Vor der Risikoanalyse müssen veröffentlichte Versionen fixiert
                werden.
            </p>
            <form @submit.prevent="saveContext">
                <fieldset
                    :disabled="!capabilities.write || contextForm.processing"
                    class="mt-4 grid gap-3 disabled:opacity-60"
                >
                    <label
                        >Methodik
                        <select
                            v-model="contextForm.methodology_version_id"
                            required
                            @change="
                                preview = false;
                                confirmed = false;
                            "
                        >
                            <option value="">Bitte auswählen</option>
                            <option
                                v-for="v in methodologies"
                                :key="v.id"
                                :value="v.id"
                            >
                                {{ v.name }} v{{ v.version }} ·
                                {{ v.publishedAt }}
                            </option>
                        </select></label
                    >
                    <label
                        >Katalog
                        <select
                            v-model="contextForm.catalog_version_id"
                            required
                            @change="
                                preview = false;
                                confirmed = false;
                            "
                        >
                            <option value="">Bitte auswählen</option>
                            <option
                                v-for="v in catalogs"
                                :key="v.id"
                                :value="v.id"
                            >
                                {{ v.name }} v{{ v.version }} ·
                                {{ v.publishedAt }}
                            </option>
                        </select></label
                    >
                    <button
                        v-if="context && !preview"
                        type="button"
                        @click="preview = true"
                    >
                        Migrationsvorschau anzeigen
                    </button>
                    <div v-if="context && preview" role="status">
                        <p>
                            Migration auf Methodik
                            {{
                                methodologies.find(
                                    (v) =>
                                        v.id ===
                                        contextForm.methodology_version_id,
                                )?.name
                            }}
                            v{{
                                methodologies.find(
                                    (v) =>
                                        v.id ===
                                        contextForm.methodology_version_id,
                                )?.version
                            }}
                            und Katalog
                            {{
                                catalogs.find(
                                    (v) =>
                                        v.id === contextForm.catalog_version_id,
                                )?.name
                            }}
                            v{{
                                catalogs.find(
                                    (v) =>
                                        v.id === contextForm.catalog_version_id,
                                )?.version
                            }}. Aktuelle Bewertungen und Entscheidungen werden
                            ungültig; neue Analysezyklen und
                            Gefährdungsprüfungen sind erforderlich. Historie
                            bleibt erhalten.
                        </p>
                        <label
                            ><input v-model="confirmed" type="checkbox" />
                            Versionswechsel und erneute Prüfung
                            bestätigen</label
                        >
                    </div>
                    <button
                        v-if="!context || preview"
                        type="submit"
                        :disabled="!!context && !confirmed"
                    >
                        {{
                            context
                                ? 'Versionen migrieren'
                                : 'Kontext initialisieren'
                        }}
                    </button>
                </fieldset>
                <RiskFormFeedback
                    :errors="contextForm.errors"
                    :success="contextForm.recentlySuccessful"
                />
            </form>
        </section>
        <form
            class="my-5 flex flex-wrap gap-3"
            @submit.prevent="
                filter.get(base, { preserveState: true, replace: true })
            "
        >
            <label
                >Zieltyp
                <select v-model="filter.target_type">
                    <option value="">Alle</option>
                    <option value="process">Prozess</option>
                    <option value="asset">Asset</option>
                </select></label
            >
            <label
                >Gefährdung
                <select v-model="filter.threat">
                    <option value="">Alle</option>
                    <option v-for="t in threats" :key="t.id" :value="t.id">
                        {{ t.code }} · {{ t.title }}
                    </option>
                </select></label
            >
            <label
                >Kategorie
                <select v-model="filter.category">
                    <option value="">Alle</option>
                    <option value="low">Niedrig</option>
                    <option value="medium">Mittel</option>
                    <option value="high">Hoch</option>
                    <option value="very_high">Sehr hoch</option>
                </select></label
            >
            <label
                >Status
                <select v-model="filter.status">
                    <option value="">Alle</option>
                    <option
                        v-for="s in [
                            'draft',
                            'inherent_assessed',
                            'treatment_in_progress',
                            'residual_assessed',
                            'awaiting_decision',
                            'completed',
                            'superseded',
                        ]"
                        :key="s"
                        :value="s"
                    >
                        {{ s }}
                    </option>
                </select></label
            >
            <label
                >Erneute Prüfung
                <select v-model="filter.stale">
                    <option value="">Alle</option>
                    <option value="yes">Ja</option>
                    <option value="no">Nein</option>
                </select></label
            ><label
                >Entscheidung erforderlich
                <select v-model="filter.decision_required">
                    <option value="">Alle</option>
                    <option value="yes">Ja</option>
                    <option value="no">Nein</option>
                </select></label
            ><button type="submit" :disabled="filter.processing">
                Filtern
            </button>
        </form>
        <table class="w-full text-left">
            <caption class="text-left text-xl font-semibold">
                Ziele und Handlungsbedarf
            </caption>
            <thead>
                <tr>
                    <th scope="col">Ziel</th>
                    <th scope="col">Offene Pflichtauslöser</th>
                    <th scope="col">Veraltete Analysen</th>
                    <th scope="col">Entscheidungen</th>
                    <th scope="col">Abgeschlossen</th>
                </tr>
            </thead>
            <tbody>
                <tr
                    v-for="target in targets.filter(
                        (t) =>
                            !filter.target_type ||
                            t.type === filter.target_type,
                    )"
                    :key="`${target.type}:${target.id}`"
                >
                    <td>
                        <button
                            type="button"
                            :aria-expanded="
                                selectedId === `${target.type}:${target.id}`
                            "
                            @click="
                                selectedId =
                                    selectedId === `${target.type}:${target.id}`
                                        ? null
                                        : `${target.type}:${target.id}`
                            "
                        >
                            {{ target.key }} · {{ target.name }}
                            {{ target.active ? '' : '(historisch)' }}
                        </button>
                    </td>
                    <td>
                        {{
                            triggers.filter(
                                (t) =>
                                    t.targetId === target.id &&
                                    t.targetType === target.type &&
                                    t.status === 'open' &&
                                    t.type !== 'manual',
                            ).length
                        }}
                    </td>
                    <td>
                        {{
                            cycles.data.filter(
                                (c) =>
                                    c.targetId === target.id && c.needsReview,
                            ).length
                        }}
                    </td>
                    <td>
                        {{
                            cycles.data.filter(
                                (c) =>
                                    c.targetId === target.id &&
                                    c.decisionRequired,
                            ).length
                        }}
                    </td>
                    <td>
                        {{
                            cycles.data.filter(
                                (c) =>
                                    c.targetId === target.id &&
                                    c.status === 'completed',
                            ).length
                        }}
                    </td>
                </tr>
            </tbody>
        </table>
        <nav aria-label="Zielseiten" class="my-3 flex gap-4">
            <Link
                v-if="referencePages.target.current > 1"
                :href="
                    referenceUrl(
                        'target_page',
                        referencePages.target.current - 1,
                    )
                "
                >Vorherige Ziele</Link
            ><span
                >Zielseite {{ referencePages.target.current }} von
                {{ referencePages.target.last }}</span
            ><Link
                v-if="
                    referencePages.target.current < referencePages.target.last
                "
                :href="
                    referenceUrl(
                        'target_page',
                        referencePages.target.current + 1,
                    )
                "
                >Weitere Ziele</Link
            >
        </nav>
        <nav aria-label="Verknüpfungsreferenzen" class="my-3 flex gap-4">
            <Link
                v-if="referencePages.resource.current > 1"
                :href="
                    referenceUrl(
                        'reference_page',
                        referencePages.resource.current - 1,
                    )
                "
                preserve-state
                preserve-scroll
                >Vorherige Referenzen</Link
            ><span
                >Feststellungen, Maßnahmen und Nachweise: Referenzseite
                {{ referencePages.resource.current }} von
                {{ referencePages.resource.last }}</span
            ><Link
                v-if="
                    referencePages.resource.current <
                    referencePages.resource.last
                "
                :href="
                    referenceUrl(
                        'reference_page',
                        referencePages.resource.current + 1,
                    )
                "
                preserve-state
                preserve-scroll
                >Weitere Referenzen</Link
            >
        </nav>
        <p class="mt-3 text-sm text-slate-400">
            Analyseübersicht: Seite {{ cycles.meta.currentPage }} von
            {{ cycles.meta.lastPage }}. Zähler beziehen sich auf die aktuelle
            gefilterte Seite.
        </p>
        <nav aria-label="Risikoseiten" class="flex gap-4">
            <Link v-if="cycles.links.previous" :href="cycles.links.previous"
                >Vorherige Seite</Link
            ><Link v-if="cycles.links.next" :href="cycles.links.next"
                >Nächste Seite</Link
            >
        </nav>
        <section
            v-if="selected && context"
            :key="selectedId ?? ''"
            class="my-6 rounded border border-cyan-700 p-5"
            aria-label="Zielarbeitsbereich"
        >
            <h2 class="text-2xl font-semibold">
                {{ selected.key }} · {{ selected.name }}
            </h2>
            <h3 class="my-3 text-lg font-semibold">
                Schutzbedarf und Graphkontext
            </h3>
            <p v-for="need in selected.protectionNeeds" :key="need.objective">
                {{ need.objective }}: {{ need.level ?? 'offen' }}
                {{ need.needsReview ? '· erneute Prüfung erforderlich' : ''
                }}<span v-for="source in need.sources" :key="source.source_id">
                    · Quelle {{ source.source_type }} {{ source.source_id }} ·
                    {{ source.path.join(' → ') }}</span
                >
            </p>
            <RiskTriggerList
                :base="base"
                :url="`${targetUrl}/triggers`"
                :triggers="targetTriggers"
                :can-write="capabilities.write && selected.active"
            />
            <ThreatOverview
                :base="base"
                :target="selected"
                :threats="threats"
                :can-write="capabilities.write && selected.active"
            />
            <RiskAnalysisForm
                :url="`${targetUrl}/analyses`"
                :threats="threats"
                :triggers="targetTriggers"
                :can-write="capabilities.write && selected.active"
            />
            <template v-if="methodology"
                ><RiskCycleWorkspace
                    v-for="cycle in targetCycles"
                    :key="cycle.id"
                    :base="base"
                    :cycle="cycle"
                    :methodology="methodology"
                    :resources="resources"
                    :can-write="capabilities.write && selected.active"
            /></template>
            <RiskCycleHistory :cycles="targetCycles" />
        </section>
    </AppLayout>
</template>
<style scoped>
:deep(input:not([type='checkbox'])),
:deep(select),
:deep(textarea) {
    display: block;
    width: 100%;
    max-width: 36rem;
    border: 1px solid #475569;
    border-radius: 0.375rem;
    background: #020617;
    padding: 0.5rem;
    margin-top: 0.25rem;
}
:deep(button) {
    border: 1px solid #475569;
    border-radius: 0.375rem;
    padding: 0.5rem 0.75rem;
}
:deep(button:disabled) {
    opacity: 0.5;
}
:deep(th),
:deep(td) {
    padding: 0.75rem 0.5rem;
    border-bottom: 1px solid #334155;
}
</style>
