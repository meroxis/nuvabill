/**
 * The power buttons of a server panel on a client's service page (theme views client/services/vps-panel
 * and server-panel). Power buttons post in the background, show a pending state such as "Starting…",
 * and the panel checks the server's status until it gets there. Without JavaScript the same buttons
 * post as a normal form.
 *
 * Other power actions (such as "wol" for Wake on LAN) have no state to wait for: they show the
 * server's message only. A button with data-destructive sends confirm=1 once the client said yes.
 *
 * A button that shows a value once (such as VNC details) posts in the background too. The value
 * comes back in the answer and is kept in memory only, never in the page address or storage: it is
 * dropped after a minute (unless the client keeps it open), with Hide, or when the page is left.
 */
import { SECRET_SECONDS } from './server-panel.js';

const EXPECTED = { start: 'running', restart: 'running', stop: 'stopped', poweroff: 'stopped', reset: 'running', 'power-cycle': 'running' };

/** Actions that only make sense on a stopped server. */
const WHEN_STOPPED = ['start', 'wol'];

/** Answers that carry a message for the client. Others, such as an expired session (419), do not. */
const READABLE = [403, 404, 409, 413, 422, 429, 503];
const TONES = { running: 'good', stopped: 'crit', suspended: 'warn' };

/**
 * @param {{
 *   state: string,
 *   pending: string|null,
 *   statusUrl: string,
 *   labels: Object<string, string>,
 *   pendingLabels: Object<string, string>,
 *   messages: {failed: string, slow: string, stillRunning: string, reached: Object<string, string>},
 *   interval?: number,
 *   tries?: number,
 * }} config
 */
export function vpsPanel(config) {
    const interval = config.interval ?? 4000;
    const maxTries = config.tries ?? 30;

    return {
        state: config.state,
        pending: EXPECTED[config.pending] ? config.pending : null,
        busy: false,
        message: '',
        timer: null,
        tries: 0,
        // Counts the waits for a new state, so a check from an older wait stops once a new one began.
        run: 0,
        // A value shown once, as rows of label and value, and the seconds until it closes.
        secret: null,
        secretLeft: 0,
        secretTimer: null,
        revealing: false,
        revealMessage: '',
        copied: null,
        copiedTimer: null,

        init() {
            if (this.pending) {
                this.wait();
            }
        },

        destroy() {
            clearTimeout(this.timer);
            clearTimeout(this.copiedTimer);
            this.hideSecret();
        },

        get label() {
            return this.pending ? config.pendingLabels[this.pending] : (config.labels[this.state] ?? config.labels.unknown);
        },

        get tone() {
            return this.pending ? 'info' : (TONES[this.state] ?? null);
        },

        /**
         * Which power buttons fit the state: all of them while it is unknown.
         */
        shows(action) {
            if (this.state === 'unknown') {
                return true;
            }

            if (this.state === 'suspended') {
                return false;
            }

            if (WHEN_STOPPED.includes(action)) {
                return this.state === 'stopped';
            }

            // An action without a state to reach, such as pressing the power button, always fits.
            return EXPECTED[action] ? this.state === 'running' : true;
        },

        /**
         * Whether a power button is locked: all of them while an action runs, except Power off while
         * a shutdown is awaited, as the shutdown message tells the client to use it when the server
         * does not stop.
         */
        locked(action) {
            return this.busy && !(action === 'poweroff' && this.pending === 'stop');
        },

        async power(event) {
            const button = event.submitter;
            const action = button?.dataset.action;

            if (!button || typeof action !== 'string' || action === '') {
                return;
            }

            event.preventDefault();

            if (this.locked(action) || (button.dataset.confirm && !window.confirm(button.dataset.confirm))) {
                return;
            }

            // A Power off during a shutdown ends the wait for that shutdown.
            clearTimeout(this.timer);
            this.run += 1;
            this.pending = null;
            this.busy = true;
            this.message = '';

            let response = null;
            let data = {};
            const body = new FormData(event.target);

            // A destructive action is refused without its confirm field; the client just said yes.
            if (button.dataset.destructive !== undefined) {
                body.append('confirm', '1');
            }

            try {
                response = await fetch(button.formAction, {
                    method: 'POST',
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body,
                    credentials: 'same-origin',
                    redirect: 'error',
                });
                data = await response.json().catch(() => ({}));
            } catch {
                response = null;
            }

            // A refusal (422), a locked action (403, as on the demo), a busy server (409) or too many
            // tries (429) come with a message for the client. Other answers, such as an expired
            // session (419), get the general message.
            const readable = response && (response.ok || READABLE.includes(response.status));

            if (!readable || typeof data.message !== 'string') {
                this.busy = false;
                this.message = config.messages.failed;

                return;
            }

            this.message = data.message;

            if (!data.ok) {
                this.busy = false;

                return;
            }

            if (!EXPECTED[action]) {
                this.busy = false;
                this.state = ['running', 'stopped', 'suspended', 'unknown'].includes(data.state) ? data.state : this.state;

                return;
            }

            this.pending = action;

            if (data.state === EXPECTED[this.pending]) {
                this.finish(data.state, false);
            } else {
                this.wait();
            }
        },

        wait() {
            const run = ++this.run;

            this.busy = true;
            this.tries = 0;
            clearTimeout(this.timer);
            this.timer = setTimeout(() => this.check(run), interval);
        },

        async check(run) {
            const state = await this.fetchState();

            // Another action began while this check waited for its answer.
            if (run !== this.run) {
                return;
            }

            if (state !== null && state === EXPECTED[this.pending]) {
                this.finish(state, true);

                return;
            }

            this.tries += 1;

            if (this.tries < maxTries) {
                this.timer = setTimeout(() => this.check(run), interval);

                return;
            }

            const action = this.pending;

            this.state = state ?? this.state;
            this.pending = null;
            this.busy = false;
            this.message = action === 'stop' && this.state === 'running' ? config.messages.stillRunning : config.messages.slow;
        },

        finish(state, announce) {
            clearTimeout(this.timer);
            this.run += 1;
            this.state = state;
            this.pending = null;
            this.busy = false;

            if (announce) {
                this.message = config.messages.reached[state] ?? '';
            }
        },

        async refresh() {
            if (this.busy) {
                return;
            }

            this.busy = true;
            const state = await this.fetchState();
            this.busy = false;
            this.state = state ?? this.state;
            this.message = state === null ? config.messages.failed : (config.messages.reached[state] ?? '');
        },

        /**
         * Ask for a value that is shown once, such as VNC details, and show it.
         */
        async reveal(event) {
            event.preventDefault();

            if (this.revealing) {
                return;
            }

            this.revealing = true;
            this.revealMessage = '';
            this.hideSecret();

            let response = null;
            let data = {};

            try {
                response = await fetch(event.target.action, {
                    method: 'POST',
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body: new FormData(event.target),
                    credentials: 'same-origin',
                    redirect: 'error',
                });
                data = await response.json().catch(() => ({}));
            } catch {
                response = null;
            }

            this.revealing = false;

            const readable = response && (response.ok || READABLE.includes(response.status));

            if (!readable || typeof data.message !== 'string') {
                this.revealMessage = config.messages.failed;

                return;
            }

            this.revealMessage = data.message;

            if (data.ok && Array.isArray(data.secret)) {
                this.showSecret(data.secret);
            }
        },

        showSecret(list) {
            const items = list
                .filter((item) => item !== null && typeof item === 'object' && typeof item.value === 'string')
                .map((item) => ({ label: String(item.label ?? ''), value: item.value }));

            if (items.length === 0) {
                return;
            }

            this.secret = items;
            this.secretLeft = SECRET_SECONDS;
            clearTimeout(this.secretTimer);
            this.secretTimer = setTimeout(() => this.tick(), 1000);
        },

        tick() {
            this.secretLeft -= 1;

            if (this.secretLeft <= 0) {
                this.hideSecret();
            } else {
                this.secretTimer = setTimeout(() => this.tick(), 1000);
            }
        },

        /**
         * Keep the value open until the client hides it or leaves the page: stops the countdown,
         * for anyone who needs more than a minute to read or copy it. It stays in memory only.
         */
        keepSecret() {
            clearTimeout(this.secretTimer);
            this.secretLeft = 0;
        },

        hideSecret() {
            clearTimeout(this.secretTimer);
            this.secret = null;
            this.secretLeft = 0;
            this.copied = null;
        },

        async copy(text, id = null) {
            try {
                await globalThis.navigator.clipboard.writeText(String(text));
                this.copied = id ?? text;
                clearTimeout(this.copiedTimer);
                this.copiedTimer = setTimeout(() => {
                    this.copied = null;
                }, 2000);
            } catch {
                globalThis.window?.prompt('', String(text));
            }
        },

        /**
         * Disable a tool form's button once it is really sent, after the page's own "Are you sure?".
         */
        lock(event) {
            setTimeout(() => {
                if (!event.defaultPrevented && event.submitter) {
                    event.submitter.disabled = true;
                    event.submitter.setAttribute('aria-busy', 'true');
                }
            });
        },

        /**
         * The server's state, or null when it cannot be read. It is sent as a background request,
         * so the session does not keep the status address as the page to go back to.
         */
        async fetchState() {
            try {
                const response = await fetch(config.statusUrl, {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                    redirect: 'error',
                });

                if (!response.ok) {
                    return null;
                }

                const data = await response.json();

                return ['running', 'stopped', 'suspended', 'unknown'].includes(data.state) ? data.state : null;
            } catch {
                return null;
            }
        },
    };
}
