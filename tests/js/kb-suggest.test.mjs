import assert from 'node:assert/strict';
import { afterEach, beforeEach, mock, test } from 'node:test';
import { kbSuggest } from '../../resources/js/kb-suggest.js';

// Each fetch waits until the test answers it by hand.
let requests = [];

beforeEach(() => {
    requests = [];
    mock.timers.enable({ apis: ['setTimeout'] });
    globalThis.fetch = (url) =>
        new Promise((resolve) => {
            requests.push({ url, answer: (articles, ok = true) => resolve({ ok, json: async () => ({ articles }) }) });
        });
});

afterEach(() => {
    mock.timers.reset();
    delete globalThis.fetch;
});

const settle = () => new Promise((resolve) => setImmediate(resolve));

test('a late answer does not bring suggestions back after the subject got too short', async () => {
    const box = kbSuggest({ url: '/knowledgebase/suggest' });

    box.lookup('ema');
    mock.timers.tick(400);
    box.lookup('em');
    requests[0].answer([{ title: 'Email setup', url: '/kb/a' }]);
    await settle();

    assert.deepEqual(box.articles, []);
});

test('answers that come back out of order keep the newest subject', async () => {
    const box = kbSuggest({ url: '/knowledgebase/suggest' });

    box.lookup('email');
    mock.timers.tick(400);
    box.lookup('emails');
    mock.timers.tick(400);
    requests[1].answer([{ title: 'Emails', url: '/kb/b' }]);
    await settle();
    requests[0].answer([{ title: 'Email', url: '/kb/a' }]);
    await settle();

    assert.equal(requests.length, 2);
    assert.equal(box.articles[0].title, 'Emails');
});

test('a failed lookup is tried again', async () => {
    const box = kbSuggest({ url: '/knowledgebase/suggest' });

    box.lookup('backup');
    mock.timers.tick(400);
    requests[0].answer([], false);
    await settle();
    box.lookup('backup');
    mock.timers.tick(400);

    assert.equal(requests.length, 2);
});
