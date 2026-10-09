/**
 * The server panel on a client's service page (theme view client/services/vps-panel). Power buttons
 * post in the background, show a pending state such as "Starting…", and the panel checks the
 * server's status until it gets there. Without JavaScript the same buttons post as a normal form.
 */
const EXPECTED = { start: 'running', restart: 'running', stop: 'stopped', poweroff: 'stopped' };
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

        init() {
            if (this.pending) {
                this.wait();
            }
        },

        destroy() {
            clearTimeout(this.timer);
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

            return action === 'start' ? this.state === 'stopped' : this.state === 'running';
        },

        async power(event) {
            const button = event.submitter;

            if (!button || !EXPECTED[button.dataset.action]) {
                return;
            }

            event.preventDefault();

            if (this.busy || (button.dataset.confirm && !window.confirm(button.dataset.confirm))) {
                return;
            }

            this.busy = true;
            this.message = '';

            let response = null;
            let data = {};

            try {
                response = await fetch(button.formAction, {
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

            if (!response || (!response.ok && response.status !== 422) || typeof data.message !== 'string') {
                this.busy = false;
                this.message = config.messages.failed;

                return;
            }

            this.message = data.message;

            if (!data.ok) {
                this.busy = false;

                return;
            }

            this.pending = button.dataset.action;

            if (data.state === EXPECTED[this.pending]) {
                this.finish(data.state, false);
            } else {
                this.wait();
            }
        },

        wait() {
            this.busy = true;
            this.tries = 0;
            clearTimeout(this.timer);
            this.timer = setTimeout(() => this.check(), interval);
        },

        async check() {
            const state = await this.fetchState();

            if (state !== null && state === EXPECTED[this.pending]) {
                this.finish(state, true);

                return;
            }

            this.tries += 1;

            if (this.tries < maxTries) {
                this.timer = setTimeout(() => this.check(), interval);

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

        async fetchState() {
            try {
                const response = await fetch(config.statusUrl, {
                    headers: { Accept: 'application/json' },
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
