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
    if (filename.endsWith('.vue')) {
        const { descriptor } = parse(source, { filename });
        source = compileScript(descriptor, { id: filename }).content;
    }
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
    const component = load(resolve(root, 'components/bcm', name));
    assert.equal(
        typeof component.default?.setup,
        'function',
        `${name} provides the real workspace form`,
    );
    return component.default.setup(props, { expose() {}, emit() {} });
}

const revision = reactive({
    id: 'bia-1',
    version: 1,
    status: 'draft',
    lock_version: 3,
    owner_name: 'Verantwortliche',
    mtpd_minutes: 500,
    rto_minutes: 60,
    rpo_minutes: 0,
    rpo_not_applicable: false,
    rpo_reason: null,
    target_reason: 'Begründung',
    minutes: [90, 450],
    impacts: [],
    freshness: { current: false, reasons: ['not_approved'] },
});
const targetProps = reactive({
    revision,
    base: '/bcm/bia/bia-1',
    canEdit: true,
    canApprove: true,
});
const targets = setup('BiaTargetsForm.vue', targetProps);
assert.equal(
    targets.form.rpo_minutes,
    0,
    'Zero data loss is not an unknown target',
);
targets.form.rpo_minutes = '0';
targets.form.rto_minutes = '';
const payload = targets.payload();
assert.equal(payload.rpo_minutes, 0);
assert.equal(payload.rto_minutes, null);
targets.form.target_reason = 'Ungespeicherte Änderung';
targets.form.setError('target_reason', 'Bitte prüfen');
assert.equal(targets.form.target_reason, 'Ungespeicherte Änderung');
assert.equal(
    targets.form.impacts[0].minutes,
    90,
    'Custom pinned time remains in the form',
);
assert.equal(targets.form.impacts.length, 8);
let saves = 0;
let saveOptions;
targets.form.put = (_url, options) => {
    saves++;
    saveOptions = options;
};
targets.save();
assert.equal(saves, 1);
assert.equal(saveOptions.onHttpException({ status: 409 }), false);
targets.save();
assert.equal(saves, 1, 'Stale form cannot retry before explicit refresh');
assert.equal(targets.form.target_reason, 'Ungespeicherte Änderung');
assert.equal(targets.form.lock_version, 3);
revision.lock_version = 4;
await nextTick();
assert.equal(targets.state.stale.value, true);
assert.equal(
    targets.form.lock_version,
    3,
    'New props do not silently unlock stale edits',
);
targets.state.refreshed();
assert.equal(targets.form.lock_version, 4);
assert.equal(targets.state.stale.value, false);
assert.equal(targets.form.target_reason, 'Ungespeicherte Änderung');
targets.save();
assert.equal(saves, 2);
revision.lock_version = 5;
await nextTick();
targets.save();
assert.equal(
    saves,
    2,
    'Changed server generation locks edits until explicit refresh',
);
targets.state.refreshed();
targetProps.canEdit = false;
targets.save();
assert.equal(saves, 2, 'Revoked edit capability blocks an existing dirty form');

const history = setup('BiaTargetsForm.vue', {
    revision: { ...revision, status: 'approved' },
    base: '/bcm/bia/history',
    canEdit: false,
    canApprove: false,
});
assert.equal(history.form, null, 'Approved history never binds a live form');
const config = setup('BiaConfigurationPanel.vue', {
    configuration: {
        id: 'cfg',
        version: 2,
        minutes: [90, 450],
        lock_version: 2,
        approved_at: null,
    },
    base: '/bcm',
    canWrite: true,
    lockVersion: 2,
});
config.form.minutesText = '95, 455';
config.form.setError('minutes', 'Ungültige Zeitstufen');
assert.equal(
    config.form.minutesText,
    '95, 455',
    'Validation keeps custom configuration input',
);
const display = load(resolve(root, 'components/bcm/biaState.ts'));
assert.equal(display.minuteLabel(null), 'Unbekannt');
assert.equal(display.minuteLabel(0), '0 Minuten');
assert.equal(
    display.firstCriticalMinute([
        { level: 4, minutes: 450 },
        { level: 4, minutes: 90 },
    ]),
    90,
);
assert.equal(display.firstCriticalMinute([{ level: null, minutes: 90 }]), null);
assert.equal(
    display.conflictLabel('dependency_target_unknown'),
    'Ziel der Abhängigkeit unbekannt',
);
console.log(
    'BIA Vue/Inertia state checks passed (zero, custom times, 409, history, critical/unknown display).',
);
