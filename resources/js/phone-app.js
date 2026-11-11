/**
 * Admin phone app: installs the admin area on the home screen and turns push alerts on or off for
 * this device. registerAdminApp() runs on every admin page; the rest is the phoneApp component.
 *
 * Usage: x-data="phoneApp(@js($config))" with {key, subscribeUrl, forgetUrl, failed, reload, devices}.
 * "devices" lists the devices of this staff member the server alerts (SHA-256 of each push
 * address). Turning alerts on sends the password typed into x-model="password".
 */
let installPrompt = null;

export function registerAdminApp() {
    const worker = document.body?.dataset.adminApp;

    if (!worker || !('serviceWorker' in navigator)) {
        return;
    }

    // Android and desktop Chrome offer their own install button; keep it for "Install the app".
    window.addEventListener('beforeinstallprompt', (event) => {
        event.preventDefault();
        installPrompt = event;
        window.dispatchEvent(new CustomEvent('nuvabill-install-ready'));
    });

    navigator.serviceWorker.register(worker, { scope: document.body.dataset.adminScope }).catch(() => {});
}

function isIos() {
    return /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
}

function keyBytes(text) {
    const base64 = (text + '='.repeat((4 - (text.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/');

    return Uint8Array.from(atob(base64), (character) => character.charCodeAt(0));
}

export function phoneApp(config) {
    return {
        // checking | unsupported | ios-install | blocked | off | on
        state: 'checking',
        busy: false,
        error: '',
        password: '',
        canInstall: Boolean(installPrompt),
        installed: window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true,
        ios: isIos(),

        async init() {
            window.addEventListener('nuvabill-install-ready', () => {
                this.canInstall = true;
            });

            if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) {
                // iPhones only allow alerts once the app is on the Home Screen.
                this.state = this.ios && !this.installed ? 'ios-install' : 'unsupported';

                return;
            }

            if (Notification.permission === 'denied') {
                this.state = 'blocked';

                return;
            }

            try {
                const subscription = await (await this.registration()).pushManager.getSubscription();
                this.state = subscription && (await this.known(subscription)) ? 'on' : 'off';
            } catch {
                this.state = 'off';
            }
        },

        // A device the server no longer alerts (removed on the profile page, or together with an
        // old password) is off, even when the browser still remembers it.
        async known(subscription) {
            if (!Array.isArray(config.devices) || !window.crypto?.subtle) {
                return true;
            }

            const digest = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(subscription.endpoint));
            const hash = Array.from(new Uint8Array(digest), (byte) => byte.toString(16).padStart(2, '0')).join('');

            return config.devices.includes(hash);
        },

        registration() {
            // "ready" never settles when the worker could not be registered, so stop waiting after a while.
            return Promise.race([
                navigator.serviceWorker.ready,
                new Promise((resolve, reject) => setTimeout(() => reject(new Error(config.failed)), 8000)),
            ]);
        },

        async install() {
            if (!installPrompt) {
                return;
            }

            installPrompt.prompt();
            await installPrompt.userChoice.catch(() => null);
            installPrompt = null;
            this.canInstall = false;
        },

        async turnOn() {
            this.busy = true;
            this.error = '';

            try {
                const permission = await Notification.requestPermission();

                if (permission !== 'granted') {
                    this.state = permission === 'denied' ? 'blocked' : 'off';

                    return;
                }

                const registration = await this.registration();
                const subscription = (await registration.pushManager.getSubscription())
                    || (await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyBytes(config.key) }));

                await this.send('POST', config.subscribeUrl, { ...subscription.toJSON(), current_password: this.password });
                this.password = '';
                this.state = 'on';

                if (config.reload) {
                    window.location.reload();
                }
            } catch (exception) {
                this.error = exception.message || config.failed;
            } finally {
                this.busy = false;
            }
        },

        async turnOff() {
            this.busy = true;
            this.error = '';

            try {
                const subscription = await (await this.registration()).pushManager.getSubscription();

                if (subscription) {
                    await this.send('DELETE', config.forgetUrl, { endpoint: subscription.endpoint });
                    await subscription.unsubscribe();
                }

                this.state = 'off';

                if (config.reload) {
                    window.location.reload();
                }
            } catch (exception) {
                this.error = exception.message || config.failed;
            } finally {
                this.busy = false;
            }
        },

        async send(method, url, body) {
            const response = await fetch(url, {
                method,
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                },
                body: JSON.stringify(body),
            });
            const answer = await response.json().catch(() => ({}));

            if (!response.ok) {
                throw new Error(answer.message || config.failed);
            }

            return answer;
        },
    };
}
