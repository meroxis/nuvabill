import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { afterEach, beforeEach, mock, test } from 'node:test';
import { phoneApp } from '../../resources/js/phone-app.js';

// The script stops waiting for the service worker after a few seconds; that timer never runs here.
beforeEach(() => mock.timers.enable({ apis: ['setTimeout'] }));
afterEach(() => mock.timers.reset());

const ENDPOINT = 'https://fcm.googleapis.com/fcm/send/this-device';
const sha256 = (text) => createHash('sha256').update(text).digest('hex');

/**
 * A browser that can show alerts and already holds a push subscription for this site. Returns the
 * requests the page sent to the server.
 */
function browser(answer = { ok: true, body: {} }) {
    const requests = [];
    const subscription = { endpoint: ENDPOINT, toJSON: () => ({ endpoint: ENDPOINT, keys: { p256dh: 'key', auth: 'auth' } }) };
    const pushManager = { getSubscription: async () => subscription, subscribe: async () => subscription };

    globalThis.window = {
        crypto: globalThis.crypto,
        matchMedia: () => ({ matches: false }),
        navigator: {},
        addEventListener() {},
        location: { reload() {} },
        PushManager: class {},
        Notification: class {},
    };
    globalThis.Notification = { permission: 'granted', requestPermission: async () => 'granted' };
    globalThis.document = { querySelector: () => ({ content: 'csrf' }) };
    Object.defineProperty(globalThis, 'navigator', {
        value: { userAgent: 'Android', platform: 'Linux', maxTouchPoints: 5, serviceWorker: { ready: Promise.resolve({ pushManager }) } },
        configurable: true,
        writable: true,
    });
    globalThis.fetch = async (url, options) => {
        requests.push({ url, method: options.method, body: JSON.parse(options.body) });

        return { ok: answer.ok, json: async () => answer.body };
    };

    return requests;
}

const config = (devices) => ({ key: 'AAAA', subscribeUrl: '/admin/profile/push', forgetUrl: '/admin/profile/push', failed: 'Failed', reload: false, devices });

test('a device the server alerts shows as on', async () => {
    browser();
    const app = phoneApp(config([sha256('https://fcm.googleapis.com/fcm/send/another'), sha256(ENDPOINT)]));

    await app.init();

    assert.equal(app.state, 'on');
});

test('a device the server no longer alerts shows as off, even when the browser remembers it', async () => {
    browser();
    const app = phoneApp(config([]));

    await app.init();

    assert.equal(app.state, 'off');
});

test('turning alerts on sends the typed password and then forgets it', async () => {
    const requests = browser();
    const app = phoneApp(config([]));
    app.password = 'my-password';

    await app.turnOn();

    assert.deepEqual(requests, [{ url: '/admin/profile/push', method: 'POST', body: { endpoint: ENDPOINT, keys: { p256dh: 'key', auth: 'auth' }, current_password: 'my-password' } }]);
    assert.equal(app.state, 'on');
    assert.equal(app.password, '');
    assert.equal(app.error, '');
});

test('a refused password keeps alerts off and shows the answer', async () => {
    browser({ ok: false, body: { message: 'Your password is not right.' } });
    const app = phoneApp(config([]));
    app.password = 'wrong';

    await app.init();
    await app.turnOn();

    assert.equal(app.state, 'off');
    assert.equal(app.error, 'Your password is not right.');
    assert.equal(app.busy, false);
});
