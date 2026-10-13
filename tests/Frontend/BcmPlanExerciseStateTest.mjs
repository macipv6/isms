import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { dirname, resolve } from 'node:path';
import vm from 'node:vm';
import { compileScript, parse } from '@vue/compiler-sfc';
import * as inertia from '@inertiajs/vue3';
import { nextTick, reactive } from 'vue';
import ts from 'typescript';
const require = createRequire(import.meta.url);
const root = resolve('resources/js');
function load(filename) {
    if (!existsSync(filename)) return {};
    let source = readFileSync(filename, 'utf8');
    if (filename.endsWith('.vue'))
        source = compileScript(parse(source, { filename }).descriptor, {
            id: filename,
        }).content;
    const module = { exports: {} };
    vm.runInNewContext(
        ts.transpileModule(source, {
            compilerOptions: {
                module: ts.ModuleKind.CommonJS,
                target: ts.ScriptTarget.ES2022,
            },
        }).outputText,
        {
            module,
            exports: module.exports,
            require: (id) => {
                if (id === '@inertiajs/vue3') return inertia;
                if (id.endsWith('.vue')) return {};
                if (id.startsWith('@/'))
                    return load(resolve(root, id.slice(2) + '.ts'));
                if (id.startsWith('.'))
                    return load(resolve(dirname(filename), id + '.ts'));
                return require(id);
            },
        },
    );
    return module.exports;
}
function setup(name, props) {
    const component = load(resolve(root, name));
    assert.equal(
        typeof component.default?.setup,
        'function',
        `${name} provides the real workspace form`,
    );
    return component.default.setup(props, { expose() {}, emit() {} });
}
const page = {
    data: [],
    current_page: 1,
    last_page: 1,
    total: 0,
    prev_page_url: null,
    next_page_url: null,
};
const revision = reactive({
    id: 'plan-1',
    status: 'draft',
    lock_version: 3,
    title: 'Recovery',
    scenario: 'Outage',
    steps: [
        {
            id: 'step',
            position: 0,
            instruction: 'Restore',
            owner_name: 'Owner',
            expected_minutes: 0,
        },
    ],
    roles: [
        {
            id: 'role',
            position: 0,
            role: 'Lead',
            name: 'Owner',
            deputy_name: 'Deputy',
            email: '',
            phone: '',
        },
    ],
    process_ids: ['process'],
    risk_analysis_ids: [],
    evidence_ids: [],
});
const props = reactive({
    revision,
    base: '/plans/plan-1',
    canEdit: true,
    canApprove: true,
    processOptions: page,
    riskOptions: page,
    linkedProcesses: [],
    linkedRisks: [],
    evidenceBase: '/organizations/org/projects/project',
});
const plan = setup('components/bcm/ContinuityPlanForm.vue', props);
plan.form.roles[0].deputy_name = 'Changed deputy';
plan.form.steps[0].instruction = 'Changed instruction';
plan.form.steps[0].expected_minutes = '0';
assert.equal(plan.payload().steps[0].expected_minutes, 0);
assert.equal('id' in plan.payload().roles[0], false);
assert.equal('position' in plan.payload().steps[0], false);
let saves = 0;
let options;
plan.form.put = (_url, opts) => {
    saves++;
    options = opts;
};
plan.save();
assert.equal(saves, 1);
assert.equal(options.onHttpException({ status: 409 }), false);
plan.save();
assert.equal(saves, 1);
assert.equal(plan.form.roles[0].deputy_name, 'Changed deputy');
assert.equal(plan.form.steps[0].instruction, 'Changed instruction');
revision.lock_version = 4;
await nextTick();
plan.state.refreshed();
plan.save();
assert.equal(saves, 2);
props.canEdit = false;
plan.save();
assert.equal(saves, 2);
assert.equal(
    setup('components/bcm/ContinuityPlanForm.vue', {
        ...props,
        revision: { ...revision, status: 'approved' },
        canEdit: false,
    }).form,
    null,
);
const result = {
    id: 'result-1',
    criterion_id: 'criterion-1',
    outcome: 'failed',
    observations: 'Observation',
    reason: 'Reason',
    actual_minutes: 10,
    finding_waived_at: null,
    capabilities: { edit: true, propose_finding: true, waive_finding: true },
    findings: page,
};
const exercise = reactive({
    id: 'exercise',
    status: 'conducted',
    lock_version: 7,
    criteria: [
        { id: 'criterion-1', expected_result: 'Restore' },
        { id: 'criterion-2', expected_result: 'Other' },
    ],
    actual_at: null,
    evidence_ids: [],
    freshness: { current: false, reasons: ['plan_sources_changed'] },
});
const exerciseProps = reactive({
    exercise,
    results: {
        ...page,
        data: [
            result,
            { ...result, id: 'clean-result', criterion_id: 'criterion-2' },
        ],
        total: 26,
        last_page: 2,
    },
    base: '/exercises/exercise',
    workBase: '/organizations/org/projects/project',
    capabilities: { write: true, record: true, complete: true, cancel: true },
});
const results = setup('components/bcm/BcmExerciseResults.vue', exerciseProps);
results.form.results[0].observations = 'Edited';
assert.equal(
    results.payload().results.length,
    1,
    'Only edited visible results are submitted',
);
assert.equal(results.payload().results[0].criterion_id, 'criterion-1');
exerciseProps.results.data[1].observations = 'Server updated clean result';
assert.equal(
    results.payload().results.length,
    1,
    'A server refresh does not turn an untouched row into a submitted edit',
);
assert.equal(results.needsDisposition(result), true);
assert.equal(
    results.needsDisposition({ ...result, outcome: 'not_tested' }),
    true,
);
assert.equal(
    results.needsDisposition({ ...result, finding_waived_at: 'today' }),
    false,
);
let records = 0;
results.form.post = (_url, opts) => {
    records++;
    options = opts;
};
results.record();
assert.equal(records, 1);
options.onHttpException({ status: 409 });
results.record();
assert.equal(records, 1);
assert.equal(results.form.results[0].observations, 'Edited');
results.state.refreshed();
exerciseProps.results.data[0].capabilities.edit = false;
assert.equal(
    results.payload().results.length,
    0,
    'Finding history freezes an existing dirty result',
);
exerciseProps.capabilities.record = false;
results.record();
assert.equal(records, 1);
results.form.defaults();
exerciseProps.results = {
    ...page,
    data: [
        {
            ...result,
            id: 'result-2',
            criterion_id: 'criterion-2',
            capabilities: {
                edit: true,
                propose_finding: true,
                waive_finding: true,
            },
        },
    ],
};
await nextTick();
assert.equal(
    results.form.isDirty,
    false,
    'Loading another result page does not create unsaved edits',
);
exerciseProps.capabilities.record = true;
results.form.acknowledge = true;
results.form.source_change_reason = 'Current sources documented';
results.record();
options.onSuccess();
await nextTick();
assert.equal(results.form.acknowledge, false);
assert.equal(
    results.form.source_change_reason,
    '',
    'New source acknowledgements require a new explicit reason',
);
assert.equal(
    results.form.isDirty,
    false,
    'Successful acknowledgement leaves the form ready for completion',
);
const closed = setup('components/bcm/BcmExerciseResults.vue', {
    ...exerciseProps,
    exercise: { ...exercise, status: 'completed' },
    capabilities: {
        write: true,
        record: false,
        complete: false,
        cancel: false,
    },
});
assert.equal(
    closed.form,
    null,
    'Completed exercises never bind result mutation forms',
);
const findingProps = reactive({
    finding: {
        id: 'finding',
        title: 'Delay',
        status: 'proposed',
        capabilities: {
            can_decide: true,
            can_create_measure: false,
            can_close: false,
        },
        measures: page,
    },
    base: '/organizations/org/projects/project',
});
const finding = setup('components/bcm/BcmFindingActions.vue', findingProps);
let decisions = 0;
finding.decision.patch = () => {
    decisions++;
};
finding.decide('accepted');
assert.equal(
    decisions,
    1,
    'Independent Finding decision remains usable after exercise completion',
);
findingProps.finding.capabilities.can_decide = false;
finding.decide('rejected');
assert.equal(
    decisions,
    1,
    'Revoked Finding decision capability is checked dynamically',
);
const draftProps = reactive({
    exercise: {
        ...exercise,
        status: 'draft',
        exercise_type: 'tabletop',
        planned_at: null,
        leader_name: 'Lead',
        participants: 'Team',
        scenario: 'Outage',
    },
    base: '/exercises/exercise',
    workBase: '/organizations/org/projects/project',
    capabilities: { write: true, edit: true, plan: true },
    planOptions: page,
});
const draft = setup('components/bcm/BcmExerciseForm.vue', draftProps);
draft.form.criteria[0].expected_result = 'Changed criterion';
assert.equal('id' in draft.payload().criteria[0], false);
let draftSaves = 0;
draft.form.put = (_url, opts) => {
    draftSaves++;
    options = opts;
};
draft.save();
assert.equal(draftSaves, 1);
options.onHttpException({ status: 409 });
draft.save();
assert.equal(draftSaves, 1);
assert.equal(draft.form.criteria[0].expected_result, 'Changed criterion');
draft.state.refreshed();
draftProps.capabilities.edit = false;
draft.save();
assert.equal(draftSaves, 1);
const sources = setup('components/bcm/BcmSourceHistory.vue', {
    base: '/bcm',
    sources: page,
    history: page,
    kind: 'plans',
});
assert.equal(
    sources.sourceUrl({
        target_type: 'asset',
        target_id: 'asset',
        revision_id: 'used-revision',
    }),
    '/bcm/assets/asset/recovery/used-revision',
);
assert.equal(sources.historyUrl({ id: 'historical' }), '/bcm/plans/historical');
console.log(
    'Plan/exercise Vue/Inertia state checks passed (409, partial results, disposition, freeze, history, read-only).',
);
