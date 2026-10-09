import assert from 'node:assert/strict';
import { afterEach, beforeEach, mock, test } from 'node:test';
import { vpsPanel } from '../../resources/js/vps-panel.js';

// Each fetch waits until the test answers it by hand.
let requests = [];
let confirms = [];

beforeEach(() => {
    requests = [];
    confirms = [];
    mock.timers.enable({ apis: ['setTimeout'] });
    globalThis.FormData = class {};
    globalThis.window = { confirm: (message) => confirms.push(message) > 0 };
    globalThis.fetch = (url, options = {}) =>
        new Promise((resolve) => {
            requests.push({
                url,
                options,
                answer: (body, status = 200) => resolve({ ok: status >= 200 && status < 300, status, json: async () => body }),
                fail: () => resolve(Promise.reject(new TypeError('Network error'))),
            });
        });
});

afterEach(() => {
    mock.timers.reset();
    delete globalThis.fetch;
    delete globalThis.window;
    delete globalThis.FormData;
});

const settle = () => new Promise((resolve) => setImmediate(resolve));

const config = (extra = {}) => ({
    state: 'stopped',
    pending: null,
    statusUrl: '/client/services/5/panel-status',
    labels: { running: 'Running', stopped: 'Stopped', suspended: 'Suspended', unknown: 'Unknown' },
    pendingLabels: { start: 'Starting…', stop: 'Shutting down…', restart: 'Restarting…', poweroff: 'Powering off…' },
    messages: {
        failed: 'That did not work.',
        slow: 'Not yet.',
        stillRunning: 'Still running.',
        reached: { running: 'The server is running.', stopped: 'The server is stopped.', suspended: 'Suspended.', unknown: 'No state.' },
    },
    ...extra,
});

const click = (action, confirm) => {
    const event = {
        defaultPrevented: false,
        target: {},
        submitter: { dataset: { action, ...(confirm ? { confirm } : {}) }, formAction: `/client/services/5/panel/${action}` },
        preventDefault() {
            this.defaultPrevented = true;
        },
    };

    return event;
};

test('a start shows the pending state and checks until the server runs', async () => {
    const panel = vpsPanel(config());

    panel.power(click('start'));
    await settle();

    assert.equal(panel.busy, true);
    assert.equal(requests[0].url, '/client/services/5/panel/start');
    assert.equal(requests[0].options.headers.Accept, 'application/json');
    requests[0].answer({ ok: true, message: 'The VPS is starting.', pending: 'start', state: 'stopped' });
    await settle();

    assert.equal(panel.label, 'Starting…');
    assert.equal(panel.tone, 'info');
    assert.equal(panel.message, 'The VPS is starting.');

    mock.timers.tick(4000);
    await settle();
    requests[1].answer({ state: 'stopped' });
    await settle();
    assert.equal(panel.pending, 'start');

    mock.timers.tick(4000);
    await settle();
    assert.equal(requests[2].url, '/client/services/5/panel-status');
    requests[2].answer({ state: 'running' });
    await settle();

    assert.equal(panel.state, 'running');
    assert.equal(panel.pending, null);
    assert.equal(panel.busy, false);
    assert.equal(panel.label, 'Running');
    assert.equal(panel.message, 'The server is running.');
});

test('a power off that Virtualizor already confirmed needs no status checks', async () => {
    const panel = vpsPanel(config({ state: 'running' }));

    panel.power(click('poweroff', 'Power off?'));
    await settle();
    requests[0].answer({ ok: true, message: 'The VPS is powered off.', pending: 'poweroff', state: 'stopped' });
    await settle();

    assert.deepEqual(confirms, ['Power off?']);
    assert.equal(panel.state, 'stopped');
    assert.equal(panel.busy, false);
    mock.timers.tick(10000);
    assert.equal(requests.length, 1);
});

test('a cancelled confirmation sends nothing', async () => {
    globalThis.window.confirm = () => false;
    const panel = vpsPanel(config({ state: 'running' }));
    const event = click('stop', 'Shut down?');

    await panel.power(event);

    assert.equal(event.defaultPrevented, true);
    assert.equal(requests.length, 0);
    assert.equal(panel.busy, false);
});

test('a refused action shows its message and frees the buttons', async () => {
    const panel = vpsPanel(config({ state: 'running' }));

    panel.power(click('restart'));
    await settle();
    requests[0].answer({ ok: false, message: 'Virtualizor: The VPS is locked' }, 422);
    await settle();

    assert.equal(panel.message, 'Virtualizor: The VPS is locked');
    assert.equal(panel.busy, false);
    assert.equal(panel.pending, null);
});

test('an expired session or a network error shows the general message', async () => {
    const panel = vpsPanel(config());

    panel.power(click('start'));
    await settle();
    requests[0].answer({ message: 'CSRF token mismatch.' }, 419);
    await settle();
    assert.equal(panel.message, 'That did not work.');

    panel.power(click('start'));
    await settle();
    requests[1].fail();
    await settle();
    assert.equal(panel.message, 'That did not work.');
    assert.equal(panel.busy, false);
});

test('a shutdown that does not finish suggests a power off', async () => {
    const panel = vpsPanel(config({ state: 'running', tries: 2 }));

    panel.power(click('stop'));
    await settle();
    requests[0].answer({ ok: true, message: 'A shutdown signal was sent.', pending: 'stop', state: 'running' });
    await settle();

    for (let index = 1; index <= 2; index++) {
        mock.timers.tick(4000);
        await settle();
        requests[index].answer({ state: 'running' });
        await settle();
    }

    assert.equal(panel.pending, null);
    assert.equal(panel.busy, false);
    assert.equal(panel.message, 'Still running.');
});

test('a locked action shows the server\'s message, as on the demo', async () => {
    const panel = vpsPanel(config({ state: 'running' }));

    panel.power(click('restart'));
    await settle();
    requests[0].answer({ message: 'This is turned off in the demo, so it keeps working for every visitor.' }, 403);
    await settle();

    assert.equal(panel.message, 'This is turned off in the demo, so it keeps working for every visitor.');
    assert.equal(panel.busy, false);
    assert.equal(panel.pending, null);
});

test('power off can be used while a shutdown is awaited, and ends that wait', async () => {
    const panel = vpsPanel(config({ state: 'running' }));

    panel.power(click('stop'));
    await settle();
    requests[0].answer({ ok: true, message: 'A shutdown signal was sent.', pending: 'stop', state: 'running' });
    await settle();

    assert.equal(panel.pending, 'stop');
    assert.equal(panel.locked('poweroff'), false);
    assert.equal(panel.locked('restart'), true);

    // A status check is on its way when the client clicks Power off.
    mock.timers.tick(4000);
    await settle();
    assert.equal(requests[1].url, '/client/services/5/panel-status');

    panel.power(click('poweroff', 'Power off?'));
    await settle();
    assert.equal(requests[2].url, '/client/services/5/panel/poweroff');
    assert.equal(panel.locked('poweroff'), true, 'A second click waits for the answer.');

    // The old check answers late: it changes nothing and checks no more.
    requests[1].answer({ state: 'running' });
    await settle();
    assert.equal(panel.busy, true);

    requests[2].answer({ ok: true, message: 'The VPS is powered off.', pending: 'poweroff', state: 'stopped' });
    await settle();

    assert.equal(panel.state, 'stopped');
    assert.equal(panel.pending, null);
    assert.equal(panel.busy, false);
    assert.equal(panel.message, 'The VPS is powered off.');
    mock.timers.tick(10000);
    await settle();
    assert.equal(requests.length, 3);
});

test('status checks are sent as background requests', async () => {
    const panel = vpsPanel(config({ state: 'unknown' }));

    panel.refresh();
    await settle();

    // Laravel does not keep a background request as the page to go back to.
    assert.equal(requests[0].options.headers['X-Requested-With'], 'XMLHttpRequest');
    assert.equal(requests[0].options.headers.Accept, 'application/json');
});

test('every power button shows while the state is unknown, and the right ones otherwise', () => {
    const panel = vpsPanel(config({ state: 'unknown' }));

    assert.deepEqual(['start', 'restart', 'stop', 'poweroff'].map((action) => panel.shows(action)), [true, true, true, true]);
    panel.state = 'running';
    assert.deepEqual(['start', 'restart', 'stop', 'poweroff'].map((action) => panel.shows(action)), [false, true, true, true]);
    panel.state = 'stopped';
    assert.deepEqual(['start', 'restart', 'stop', 'poweroff'].map((action) => panel.shows(action)), [true, false, false, false]);
    panel.state = 'suspended';
    assert.deepEqual(['start', 'restart', 'stop', 'poweroff'].map((action) => panel.shows(action)), [false, false, false, false]);
});

test('a page loaded after a power action keeps waiting for the new state', async () => {
    const panel = vpsPanel(config({ state: 'stopped', pending: 'start' }));

    panel.init();
    assert.equal(panel.label, 'Starting…');
    mock.timers.tick(4000);
    await settle();
    requests[0].answer({ state: 'running' });
    await settle();

    assert.equal(panel.state, 'running');
});

test('refresh reads the status once', async () => {
    const panel = vpsPanel(config({ state: 'unknown' }));

    panel.refresh();
    await settle();
    requests[0].answer({ state: 'stopped' });
    await settle();

    assert.equal(panel.state, 'stopped');
    assert.equal(panel.message, 'The server is stopped.');
});

test('a tool form is locked only when it is really sent', async () => {
    const panel = vpsPanel(config());
    const sent = { defaultPrevented: false, submitter: { disabled: false, setAttribute() {} } };
    const cancelled = { defaultPrevented: true, submitter: { disabled: false, setAttribute() {} } };

    panel.lock(sent);
    panel.lock(cancelled);
    mock.timers.tick(0);

    assert.equal(sent.submitter.disabled, true);
    assert.equal(cancelled.submitter.disabled, false);
});
