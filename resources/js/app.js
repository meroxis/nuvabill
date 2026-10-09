import Alpine from 'alpinejs';
import automationEditor from './automation-editor';
import { aiMessage, productAi, ticketAi } from './ai-help';
import { whatsappConnect } from './chat-apps';
import { showCopied } from './copy-button';
import { kbSuggest } from './kb-suggest';
import { passkeySubmitButton } from './passkey-form';
import { phoneApp, registerAdminApp } from './phone-app';
import { vpsPanel } from './vps-panel';

/**
 * Light / dark mode. "system" follows the device; the choice is remembered on this device only.
 */
function applyTheme(choice) {
    const root = document.documentElement;

    if (choice === 'light' || choice === 'dark') {
        root.dataset.theme = choice;
    } else {
        delete root.dataset.theme;
    }
}

function storedTheme() {
    try {
        return localStorage.getItem('nb.theme') || 'system';
    } catch {
        return 'system';
    }
}

window.nuvabillToggleTheme = function () {
    const current = document.documentElement.dataset.theme
        || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
    const next = current === 'dark' ? 'light' : 'dark';

    applyTheme(next);

    try {
        localStorage.setItem('nb.theme', next);
    } catch {
        // Storage can be blocked; the theme still changes for this page.
    }
};

applyTheme(storedTheme());

/**
 * Ask before destructive actions: <form data-confirm="Suspend this service?">.
 */
document.addEventListener('submit', (event) => {
    const message = event.target instanceof HTMLFormElement ? event.target.dataset.confirm : null;

    if (message && !window.confirm(message)) {
        event.preventDefault();
    }
});

/**
 * Selects that save as soon as they change, like the language switcher: <select data-autosubmit>.
 */
document.addEventListener('change', (event) => {
    if (event.target instanceof HTMLSelectElement && event.target.dataset.autosubmit !== undefined && event.target.form) {
        event.target.form.requestSubmit();
    }
});

/**
 * Copy buttons: <button data-copy="text to copy">.
 */
const copyTimers = new WeakMap();

document.addEventListener('click', async (event) => {
    const button = event.target instanceof Element ? event.target.closest('[data-copy]') : null;

    if (!button) {
        return;
    }

    try {
        await navigator.clipboard.writeText(button.dataset.copy);
        showCopied(button, copyTimers);
    } catch {
        window.prompt('Copy this text:', button.dataset.copy);
    }
});

/**
 * Passkeys. A form with data-passkey="register" or "login" and data-options="{url}" first asks
 * the server for options, lets the browser make or use the passkey, then posts the answer in
 * its hidden "credential" field like a normal form.
 */
const base64url = {
    toBuffer(value) {
        const base64 = value.replace(/-/g, '+').replace(/_/g, '/');
        const padded = base64 + '='.repeat((4 - (base64.length % 4)) % 4);

        return Uint8Array.from(atob(padded), (char) => char.charCodeAt(0)).buffer;
    },
    fromBuffer(buffer) {
        let text = '';
        new Uint8Array(buffer).forEach((byte) => {
            text += String.fromCharCode(byte);
        });

        return btoa(text).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    },
};

function passkeyCredentialJson(credential) {
    const response = credential.response;
    const json = {
        id: credential.id,
        rawId: base64url.fromBuffer(credential.rawId),
        type: credential.type,
        response: { clientDataJSON: base64url.fromBuffer(response.clientDataJSON) },
    };

    if (response.attestationObject) {
        json.response.attestationObject = base64url.fromBuffer(response.attestationObject);
        json.transports = typeof response.getTransports === 'function' ? response.getTransports() : [];
    } else {
        json.response.authenticatorData = base64url.fromBuffer(response.authenticatorData);
        json.response.signature = base64url.fromBuffer(response.signature);
        json.response.userHandle = response.userHandle ? base64url.fromBuffer(response.userHandle) : null;
    }

    return JSON.stringify(json);
}

async function runPasskeyForm(form) {
    const error = form.querySelector('[data-passkey-error]');
    const button = passkeySubmitButton(form);
    const showError = (message) => {
        if (error) {
            error.textContent = message;
            error.hidden = false;
        }
    };

    if (error) {
        error.hidden = true;
    }
    button?.setAttribute('disabled', '');

    try {
        // The current password, or for clients without one the code we emailed them, sent under the
        // form's own field names ("current_password", "passkey_email_code").
        const proof = {};
        form.querySelectorAll('input[name$="current_password"], input[name$="email_code"]').forEach((input) => {
            proof[input.name] = input.value;
        });
        const response = await fetch(form.dataset.options, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': form.querySelector('input[name="_token"]')?.value ?? '',
            },
            body: JSON.stringify(proof),
        });
        const options = await response.json().catch(() => ({}));

        if (!response.ok) {
            showError(options.message || form.dataset.failed);

            return;
        }

        options.challenge = base64url.toBuffer(options.challenge);
        let credential;

        if (form.dataset.passkey === 'register') {
            options.user.id = base64url.toBuffer(options.user.id);
            options.excludeCredentials = (options.excludeCredentials || []).map((item) => ({ ...item, id: base64url.toBuffer(item.id) }));
            credential = await navigator.credentials.create({ publicKey: options });
        } else {
            credential = await navigator.credentials.get({ publicKey: options });
        }

        form.querySelector('input[name="credential"]').value = passkeyCredentialJson(credential);

        const remember = form.querySelector('input[type="hidden"][name="remember"]');
        const rememberBox = document.querySelector('input[type="checkbox"][name="remember"]');

        if (remember && rememberBox) {
            remember.value = rememberBox.checked ? '1' : '0';
        }

        form.submit();
    } catch (exception) {
        showError(exception?.name === 'InvalidStateError' ? (form.dataset.duplicate || form.dataset.failed) : form.dataset.failed);
    } finally {
        button?.removeAttribute('disabled');
    }
}

document.addEventListener('submit', (event) => {
    const form = event.target instanceof HTMLFormElement && event.target.dataset.passkey ? event.target : null;

    if (form && !event.defaultPrevented) {
        event.preventDefault();
        runPasskeyForm(form);
    }
});

if (window.PublicKeyCredential) {
    document.querySelectorAll('[data-passkey]').forEach((element) => {
        element.hidden = false;
    });
} else {
    document.querySelectorAll('[data-passkey-unsupported]').forEach((element) => {
        element.hidden = false;
    });
}

window.Alpine = Alpine;
Alpine.data('automationEditor', automationEditor);
Alpine.data('ticketAi', ticketAi);
Alpine.data('aiMessage', aiMessage);
Alpine.data('productAi', productAi);
Alpine.data('whatsappConnect', whatsappConnect);
Alpine.data('phoneApp', phoneApp);
Alpine.data('kbSuggest', kbSuggest);
Alpine.data('vpsPanel', vpsPanel);

// Admin pages: the service worker that shows phone alerts, and the install button.
if (document.body) {
    registerAdminApp();
} else {
    document.addEventListener('DOMContentLoaded', registerAdminApp, { once: true });
}

// Start once the page is parsed, after every deferred script, so themes, order forms and add-ons can
// load their scripts with "defer" (not blocking the first paint) and still register with Alpine first.
if (document.readyState === 'complete') {
    Alpine.start();
} else {
    document.addEventListener('DOMContentLoaded', () => Alpine.start(), { once: true });
}
