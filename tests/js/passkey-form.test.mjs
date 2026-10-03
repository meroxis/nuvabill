import assert from 'node:assert/strict';
import { test } from 'node:test';
import { passkeySubmitButton } from '../../resources/js/passkey-form.js';

/**
 * A form holding these buttons, in this order. Each button names the form it sends, as the browser
 * sets button.form from the form="" attribute or the form around it.
 */
function formWith(...owners) {
    const form = { id: 'passkey-form' };
    const buttons = owners.map((owner, index) => ({ index, form: owner === 'own' ? form : { id: owner } }));
    form.querySelectorAll = (selector) => {
        assert.equal(selector, 'button[type="submit"]');

        return buttons;
    };

    return { form, buttons };
}

test('the "Email me a code" button that comes first is not taken for the passkey button', () => {
    const { form, buttons } = formWith('account-email-code', 'own');

    assert.equal(passkeySubmitButton(form), buttons[1]);
});

test('a form with only its own button gets that button', () => {
    const { form, buttons } = formWith('own');

    assert.equal(passkeySubmitButton(form), buttons[0]);
});

test('a form whose buttons all send other forms gets none', () => {
    const { form } = formWith('account-email-code');

    assert.equal(passkeySubmitButton(form), null);
});
