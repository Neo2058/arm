const { test } = require('node:test');
const assert = require('node:assert/strict');
const { createNotificationPoller, shouldPoll, POLL_MS } = require('../src/notificationPoller');

const wait = () => new Promise((resolve) => setImmediate(resolve));
async function flushed() {
  await wait();
  await wait();
  await wait();
}

test('notification poller stays off unless features.notifications is true and the app is active', async () => {
  assert.equal(shouldPoll(false, 'active'), false);
  assert.equal(shouldPoll(true, 'background'), false);
  assert.equal(shouldPoll(true, 'inactive'), false);
  assert.equal(shouldPoll('true', 'active'), false);
  let syncs = 0;
  const poller = createNotificationPoller({
    featuresOn: false,
    setIntervalFn: () => 1,
    clearIntervalFn: () => {},
    fetchInbox: async () => { syncs += 1; return { unread: 0, items: [] }; },
  });
  poller.setAppState('active');
  await flushed();
  assert.equal(poller.isRunning(), false);
  assert.equal(syncs, 0);
});

test('notification poller runs every 30s in the foreground and stops when backgrounded', async () => {
  const intervals = [];
  let tick;
  let cleared = 0;
  let syncs = 0;
  const poller = createNotificationPoller({
    featuresOn: true,
    setIntervalFn: (fn, ms) => { tick = fn; intervals.push(ms); return 9; },
    clearIntervalFn: (id) => { cleared = id; },
    fetchInbox: async () => {
      syncs += 1;
      return { unread: 2, items: [{ id: 1, type: 'chat_message', title: 'Новое сообщение в чате «Колонна 15» (2)' }] };
    },
  });
  poller.setAppState('background');
  await flushed();
  assert.equal(poller.isRunning(), false);
  assert.equal(syncs, 0);

  poller.setAppState('active');
  await flushed();
  assert.deepEqual(intervals, [POLL_MS]);
  assert.equal(poller.isRunning(), true);
  assert.equal(syncs, 1);
  assert.equal(poller.getState().unread, 2);
  assert.equal(JSON.stringify(poller.getState()).includes('Секрет'), false);

  tick();
  await flushed();
  assert.equal(syncs, 2);

  poller.setAppState('inactive');
  assert.equal(cleared, 9);
  assert.equal(poller.isRunning(), false);
  poller.stop();
});

test('notification poller backs off on 429', async () => {
  let now = 1000;
  let calls = 0;
  let tick;
  const poller = createNotificationPoller({
    featuresOn: true,
    now: () => now,
    setIntervalFn: (fn) => { tick = fn; return 3; },
    clearIntervalFn: () => {},
    fetchInbox: async () => {
      calls += 1;
      const error = new Error('rate');
      error.response = { status: 429 };
      throw error;
    },
  });
  poller.setAppState('active');
  await flushed();
  assert.equal(calls, 1);
  now += 1000;
  tick();
  await flushed();
  assert.equal(calls, 1);
  now += 30000;
  tick();
  await flushed();
  assert.equal(calls, 2);
  poller.stop();
});
