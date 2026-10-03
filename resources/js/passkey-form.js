/**
 * The button that sends a passkey form, so it can be turned off while the passkey is made.
 *
 * A form can also hold a button that sends another form, such as "Email me a code" with
 * form="account-email-code" in the client's passkey form. That button can come first, so the first
 * submit button inside the form is not always the form's own.
 *
 * @param {HTMLFormElement} form
 * @returns {HTMLButtonElement|null}
 */
export function passkeySubmitButton(form) {
    return Array.from(form.querySelectorAll('button[type="submit"]')).find((button) => button.form === form) ?? null;
}
