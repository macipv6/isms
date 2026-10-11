import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { resolve } from 'node:path';
import vm from 'node:vm';
import { compileScript, parse } from '@vue/compiler-sfc';
import * as inertia from '@inertiajs/vue3';
import { nextTick, reactive } from 'vue';
import ts from 'typescript';

const require = createRequire(import.meta.url);
const root = resolve('resources/js/components/risk');
function evaluate(source) {
    const module = { exports: {} };
    const javascript = ts.transpileModule(source, {
        compilerOptions: {
            module: ts.ModuleKind.CommonJS,
            target: ts.ScriptTarget.ES2022,
        },
    }).outputText;
    vm.runInNewContext(javascript, {
        module,
        exports: module.exports,
        require: (id) => {
            if (id === '@inertiajs/vue3') return inertia;
            if (id.endsWith('.vue')) return {};
            if (id === './useAssessmentVersion')
                return evaluate(
                    readFileSync(
                        resolve(root, 'useAssessmentVersion.ts'),
                        'utf8',
                    ),
                );
            return require(id);
        },
    });
    return module.exports;
}
function setup(name, props) {
    const filename = resolve(root, name);
    const { descriptor } = parse(readFileSync(filename, 'utf8'), { filename });
    const compiled = compileScript(descriptor, { id: name });
    return evaluate(compiled.content).default.setup(props, { expose() {} });
}

const props = reactive({ cycle: { lockVersion: 3 }, phase: 'residual' });
const assessment = setup('RiskAssessmentForm.vue', props);
assessment.form.frequency_key = 'rare';
assessment.form.impact_key = 'limited';
assessment.form.rationale = 'Nicht gespeicherte Bewertung';
assessment.form.setError('rationale', 'Begründung prüfen');
props.cycle.lockVersion = 4; // Successful link mutation returns a new version.
await nextTick();
assert.equal(assessment.form.lock_version, 4);
assert.equal(assessment.form.rationale, 'Nicht gespeicherte Bewertung');
assert.equal(assessment.form.frequency_key, 'rare');
assert.equal(assessment.form.errors.rationale, 'Begründung prüfen');
assessment.form.processing = true;
props.cycle.lockVersion = 5;
await nextTick();
assessment.form.setError('cycle', 'Zyklus wurde geändert');
assessment.form.processing = false;
props.cycle.lockVersion = 6;
await nextTick();
assert.equal(
    assessment.form.lock_version,
    4,
    'A failed submission keeps its expected version',
);
assert.equal(assessment.form.errors.cycle, 'Zyklus wurde geändert');
assessment.version.refreshed();
assert.equal(assessment.form.lock_version, 6);
assert.equal(assessment.form.errors.cycle, undefined);
assert.equal(assessment.form.errors.rationale, 'Begründung prüfen');
assert.equal(assessment.form.rationale, 'Nicht gespeicherte Bewertung');

const entry = {
    code: 'G.1',
    title: 'Gefährdung',
    description: 'Gespeichert',
    confidentiality: false,
    integrity: true,
    availability: true,
    sort_order: 1,
    source_edition: null,
    source_reference: null,
};
const catalog = setup('CatalogVersionCard.vue', { version: { id: 'catalog' } });
catalog.edit(entry);
await nextTick();
assert.equal(
    catalog.draft.isDirty,
    false,
    'Selecting an existing entry establishes a clean baseline',
);
let published = 0;
catalog.publish.post = () => {
    published += 1;
};
catalog.draft.description = 'Ungespeicherte Änderung';
await nextTick();
catalog.publishSaved();
assert.equal(published, 0);
catalog.edit({ ...entry, code: 'G.2' });
assert.equal(
    catalog.draft.code,
    'G.1',
    'Switching entries cannot discard a dirty draft',
);
catalog.draft.defaults(); // Successful save callback.
await nextTick();
catalog.draft.processing = true;
catalog.publishSaved();
assert.equal(published, 0);
catalog.draft.processing = false;
catalog.publishSaved();
assert.equal(published, 1);

const treatment = setup('RiskTreatmentForm.vue', {
    treatment: {
        action: 'transfer',
        rationale: 'Bestehender Vertrag',
        counterparty_name: 'Dienstleister',
        counterparty_email: 'kontakt@example.test',
        cessation_statement: '',
        cessation_evidence_id: null,
    },
});
assert.equal(treatment.form.action, 'transfer');
assert.equal(treatment.form.rationale, 'Bestehender Vertrag');
assert.equal(treatment.form.counterparty_name, 'Dienstleister');
assert.equal(treatment.form.counterparty_email, 'kontakt@example.test');
console.log('Risk workspace Vue/Inertia state checks passed.');
