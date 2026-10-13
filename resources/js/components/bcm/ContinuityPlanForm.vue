<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import FormErrorList from '@/components/FormErrorList.vue';
import BcmEvidencePicker from './BcmEvidencePicker.vue';
import BcmRolesEditor from './BcmRolesEditor.vue';
import BcmStepsEditor from './BcmStepsEditor.vue';
import { nullableInteger, useBcmFormState } from './biaState';
import type {
    BcmPagination,
    BcmRiskOption,
    BcmTarget,
    PlanRevision,
} from '@/types/bcm';
const props = defineProps<{
    revision: PlanRevision | null;
    base: string;
    canEdit: boolean;
    canApprove?: boolean;
    processOptions: BcmPagination<BcmTarget>;
    riskOptions: BcmPagination<BcmRiskOption>;
    linkedProcesses?: BcmTarget[];
    linkedRisks?: BcmRiskOption[];
    evidenceBase: string;
}>();
const emit = defineEmits<{ pending: [value: boolean] }>();
const fields = {
    title: 'Titel',
    scenario: 'Szenario',
    activation_conditions: 'Aktivierungsbedingungen',
    strategy: 'Strategie',
    minimum_operation: 'Minimalbetrieb',
    normal_operation_return: 'Rückkehr zum Normalbetrieb',
} as const;
const form =
    props.canEdit && (!props.revision || props.revision.status === 'draft')
        ? useForm({
              lock_version: props.revision?.lock_version ?? 0,
              title: props.revision?.title ?? '',
              scenario: props.revision?.scenario ?? '',
              activation_conditions:
                  props.revision?.activation_conditions ?? '',
              strategy: props.revision?.strategy ?? '',
              minimum_operation: props.revision?.minimum_operation ?? '',
              normal_operation_return:
                  props.revision?.normal_operation_return ?? '',
              steps:
                  props.revision?.steps.map((row) => ({
                      instruction: row.instruction,
                      owner_name: row.owner_name,
                      expected_minutes: row.expected_minutes,
                  })) ?? [],
              roles:
                  props.revision?.roles.map((row) => ({
                      role: row.role,
                      name: row.name,
                      email: row.email,
                      phone: row.phone,
                      deputy_name: row.deputy_name,
                  })) ?? [],
              process_ids: [...(props.revision?.process_ids ?? [])],
              evidence_ids: [...(props.revision?.evidence_ids ?? [])],
              risk_analysis_ids: [...(props.revision?.risk_analysis_ids ?? [])],
          })
        : null;
const approval = useForm({ lock_version: props.revision?.lock_version ?? 0 });
const state = useBcmFormState(
    form ?? approval,
    () => props.revision?.lock_version ?? 0,
);
const dirty = computed(() => !!form?.isDirty);
watch(
    () =>
        dirty.value ||
        state.stale.value ||
        !!form?.processing ||
        approval.processing,
    (value) => emit('pending', value),
    { immediate: true },
);
function payload() {
    if (!form) return {};
    return {
        ...form.data(),
        steps: form.steps.map((row) => ({
            instruction: row.instruction,
            owner_name: row.owner_name,
            expected_minutes: nullableInteger(row.expected_minutes),
        })),
        roles: form.roles.map((row) => ({
            role: row.role,
            name: row.name,
            email: row.email,
            phone: row.phone,
            deputy_name: row.deputy_name,
        })),
    };
}
function save(): void {
    if (
        !form ||
        !props.canEdit ||
        state.stale.value ||
        form.processing ||
        approval.processing
    )
        return;
    const options = {
        preserveScroll: true,
        onHttpException: state.httpException,
        onSuccess: () => {
            state.refreshed();
            form.defaults();
        },
    };
    if (props.revision) form.transform(payload).put(props.base, options);
    else form.transform(payload).post(props.base, options);
}
function approve(): void {
    if (
        !props.canApprove ||
        dirty.value ||
        state.stale.value ||
        form?.processing ||
        approval.processing
    )
        return;
    approval.lock_version =
        form?.lock_version ?? props.revision?.lock_version ?? 0;
    approval.post(`${props.base}/approve`, {
        preserveScroll: true,
        onHttpException: state.httpException,
    });
}
function reload(): void {
    router.reload({
        onSuccess: () => {
            state.refreshed();
            approval.lock_version = props.revision?.lock_version ?? 0;
        },
    });
}
</script>
<template>
    <section class="mt-6 rounded border border-slate-700 p-4">
        <form v-if="form && canEdit" @submit.prevent="save">
            <h2 class="text-lg font-semibold">
                {{
                    revision ? 'Planentwurf bearbeiten' : 'Planentwurf anlegen'
                }}
            </h2>
            <label
                v-for="(label, field) in fields"
                :key="field"
                class="mt-3 block"
                >{{ label
                }}<input
                    v-if="field === 'title'"
                    v-model="form[field]"
                    maxlength="255"
                /><textarea v-else v-model="form[field]" maxlength="10000" />
            </label>
            <BcmStepsEditor v-model="form.steps" /><BcmRolesEditor
                v-model="form.roles"
            />
            <section class="mt-4">
                <h3 class="font-semibold">Abgedeckte Prozesse</h3>
                <p v-for="id in form.process_ids" :key="id">
                    {{
                        linkedProcesses?.find((row) => row.id === id)?.name ??
                        processOptions.data.find((row) => row.id === id)
                            ?.name ??
                        'Verknüpfter Prozess'
                    }}<button
                        type="button"
                        class="ml-3"
                        @click="
                            form.process_ids.splice(
                                form.process_ids.indexOf(id),
                                1,
                            )
                        "
                    >
                        Entfernen
                    </button>
                </p>
                <label
                    v-for="row in processOptions.data"
                    :key="row.id"
                    class="block"
                    ><input
                        v-model="form.process_ids"
                        type="checkbox"
                        :value="row.id"
                        :disabled="
                            !row.is_active ||
                            (!form.process_ids.includes(row.id) &&
                                form.process_ids.length >= 200)
                        "
                    />
                    {{ row.name }} {{ row.is_active ? '' : '· inaktiv' }}</label
                >
                <nav class="mt-2 flex gap-3" aria-label="Prozess-Auswahlseiten">
                    <Link
                        v-if="processOptions.prev_page_url"
                        :href="processOptions.prev_page_url"
                        preserve-state
                        preserve-scroll
                        >Zurück</Link
                    ><span
                        >{{ processOptions.current_page }} /
                        {{ processOptions.last_page }}</span
                    ><Link
                        v-if="processOptions.next_page_url"
                        :href="processOptions.next_page_url"
                        preserve-state
                        preserve-scroll
                        >Weiter</Link
                    >
                </nav>
            </section>
            <section class="mt-4">
                <h3 class="font-semibold">Risikoanalysen</h3>
                <p v-for="id in form.risk_analysis_ids" :key="id">
                    {{
                        linkedRisks?.find((row) => row.id === id)?.title ??
                        riskOptions.data.find((row) => row.id === id)?.title ??
                        'Verknüpfte Risikoanalyse'
                    }}<button
                        type="button"
                        class="ml-3"
                        @click="
                            form.risk_analysis_ids.splice(
                                form.risk_analysis_ids.indexOf(id),
                                1,
                            )
                        "
                    >
                        Entfernen
                    </button>
                </p>
                <label
                    v-for="row in riskOptions.data"
                    :key="row.id"
                    class="block"
                    ><input
                        v-model="form.risk_analysis_ids"
                        type="checkbox"
                        :value="row.id"
                        :disabled="
                            !form.risk_analysis_ids.includes(row.id) &&
                            form.risk_analysis_ids.length >= 200
                        "
                    />
                    {{ row.title }}</label
                >
                <nav class="mt-2 flex gap-3" aria-label="Risiko-Auswahlseiten">
                    <Link
                        v-if="riskOptions.prev_page_url"
                        :href="riskOptions.prev_page_url"
                        preserve-state
                        preserve-scroll
                        >Zurück</Link
                    ><span
                        >{{ riskOptions.current_page }} /
                        {{ riskOptions.last_page }}</span
                    ><Link
                        v-if="riskOptions.next_page_url"
                        :href="riskOptions.next_page_url"
                        preserve-state
                        preserve-scroll
                        >Weiter</Link
                    >
                </nav>
            </section>
            <BcmEvidencePicker
                v-model="form.evidence_ids"
                :base="evidenceBase"
            /><FormErrorList
                :errors="form.errors"
                :labels="fields"
                class="mt-3"
            /><button
                :disabled="
                    form.processing || approval.processing || state.stale.value
                "
                class="mt-4 rounded bg-cyan-500 px-4 py-2 text-slate-950"
            >
                Entwurf speichern
            </button>
        </form>
        <div v-else-if="revision" aria-label="Plan schreibgeschützt">
            <h2 class="text-lg font-semibold">
                Gespeicherter Plan · schreibgeschützt
            </h2>
            <dl>
                <template v-for="(label, field) in fields" :key="field"
                    ><dt class="mt-3 font-medium">{{ label }}</dt>
                    <dd class="whitespace-pre-wrap">
                        {{ revision[field] || 'Unbekannt' }}
                    </dd></template
                >
            </dl>
            <BcmStepsEditor
                :model-value="revision.steps"
                readonly
            /><BcmRolesEditor :model-value="revision.roles" readonly />
            <h3 class="mt-4 font-semibold">Abgedeckte Prozesse</h3>
            <p v-for="row in linkedProcesses" :key="row.id">
                {{ row.name }} {{ row.is_active ? '' : '· inaktiv' }}
            </p>
            <h3 class="mt-4 font-semibold">Risikoanalysen</h3>
            <p v-for="row in linkedRisks" :key="row.id">{{ row.title }}</p>
            <BcmEvidencePicker
                :model-value="revision.evidence_ids"
                :base="evidenceBase"
                readonly
            />
        </div>
        <p v-if="state.stale.value" role="alert" class="mt-3 text-amber-200">
            Der Bearbeitungsstand wurde geändert. Ihre Eingaben bleiben
            erhalten.
            <button type="button" @click="reload">
                Aktuellen Stand ausdrücklich laden
            </button>
        </p>
        <p v-if="dirty && canApprove" class="mt-3 text-amber-200">
            Änderungen vor der Freigabe speichern.
        </p>
        <FormErrorList :errors="approval.errors" :labels="fields" /><button
            v-if="canApprove"
            type="button"
            :disabled="
                dirty ||
                state.stale.value ||
                form?.processing ||
                approval.processing
            "
            class="mt-3 rounded bg-emerald-500 px-4 py-2 text-slate-950"
            @click="approve"
        >
            Gespeicherten Plan freigeben
        </button>
    </section>
</template>
<style scoped>
input:not([type='checkbox']),
textarea {
    display: block;
    width: 100%;
    margin-top: 0.25rem;
    padding: 0.5rem;
    border: 1px solid #475569;
    border-radius: 0.25rem;
    background: #020617;
}
button:disabled {
    opacity: 0.4;
}
</style>
