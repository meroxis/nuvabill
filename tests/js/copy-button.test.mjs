import assert from 'node:assert/strict';
import { mock, test } from 'node:test';
import { showCopied } from '../../resources/js/copy-button.js';

test('the label comes back after two quick clicks', () => {
    mock.timers.enable({ apis: ['setTimeout'] });
    const button = { dataset: { copied: 'Copied' }, textContent: 'Copy key' };
    const timers = new WeakMap();

    showCopied(button, timers);
    mock.timers.tick(500);
    showCopied(button, timers);
    assert.equal(button.textContent, 'Copied');

    mock.timers.tick(2000);
    assert.equal(button.textContent, 'Copy key');

    // And again on the next click.
    showCopied(button, timers);
    mock.timers.tick(1500);
    assert.equal(button.textContent, 'Copy key');
    mock.timers.reset();
});
