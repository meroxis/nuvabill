import Alpine from 'alpinejs';

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
 * Copy buttons: <button data-copy="text to copy">.
 */
document.addEventListener('click', async (event) => {
    const button = event.target instanceof Element ? event.target.closest('[data-copy]') : null;

    if (!button) {
        return;
    }

    try {
        await navigator.clipboard.writeText(button.dataset.copy);
        const label = button.textContent;
        button.textContent = button.dataset.copied || 'Copied';
        setTimeout(() => {
            button.textContent = label;
        }, 1500);
    } catch {
        window.prompt('Copy this text:', button.dataset.copy);
    }
});

window.Alpine = Alpine;
Alpine.start();
