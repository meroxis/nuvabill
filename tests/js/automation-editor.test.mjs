import assert from 'node:assert/strict';
import { test } from 'node:test';
import automationEditor from '../../resources/js/automation-editor.js';

const defs = {
    triggers: {
        'client.registered': { label: 'A client signs up', subject: 'client', timed: false },
        'invoice.paid': { label: 'An invoice is paid', subject: 'invoice', timed: false },
    },
    conditions: {},
    steps: {
        wait: { label: 'Wait', group: 'Flow', subjects: [], denied: [], fields: [] },
        add_credit: { label: 'Add wallet credit', group: 'Billing', subjects: [], denied: ['client', 'invoice'], fields: [] },
        add_fee: { label: 'Add a fee to the invoice', group: 'Billing', subjects: ['invoice'], denied: [], fields: [] },
        webhook: { label: 'Send to a web address', group: 'Other', subjects: [], denied: ['invoice'], fields: [] },
    },
};

const keys = (editor) => editor.stepGroups().flatMap(([, items]) => items.map(([key]) => key));

test('the step list leaves out steps the role does not allow for the chosen trigger', () => {
    const editor = automationEditor({ defs, value: { trigger: 'client.registered', steps: [] } });

    assert.deepEqual(keys(editor), ['wait', 'webhook']);

    editor.trigger = 'invoice.paid';
    assert.deepEqual(keys(editor), ['wait', 'add_fee']);
});

test('a saved step the role does not allow is told apart from one that does not fit the trigger', () => {
    const editor = automationEditor({ defs, value: { trigger: 'client.registered', steps: [{ type: 'add_credit', config: {} }, { type: 'add_fee', config: {} }] } });
    const [credit, fee] = editor.steps.map((step) => editor.stepDef(step));

    assert.equal(editor.fits(credit), true);
    assert.equal(editor.allowed(credit), false);
    assert.equal(editor.fits(fee), false);
    assert.equal(editor.allowed(fee), true);
    assert.equal(editor.allowed({ label: 'Old step', fields: [] }), true, 'Definitions without the list are not hidden.');
});
