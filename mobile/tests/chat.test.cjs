const { test } = require('node:test');
const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const { createChatPoller, shouldPoll, mergeMessages, POLL_MS } = require('../src/chatPoller');
const { createOutbox, fingerprintPayload } = require('../src/chatOutbox');

const uuid = '7c9e6679-7425-40de-944b-e07fc1f90ae7';
const digest = (value) => crypto.createHash('sha256').update(value, 'utf8').digest('hex');
const wait = () => new Promise((resolve) => setImmediate(resolve));
async function flushed() {
  await wait();
  await wait();
  await wait();
}

function memoryStorage(initial) {
  let disk = initial ? JSON.parse(JSON.stringify(initial)) : null;
  return {
    peek: () => disk,
    read: async () => (disk ? JSON.parse(JSON.stringify(disk)) : null),
    write: async (value) => { disk = JSON.parse(JSON.stringify(value)); },
    clear: async () => { disk = null; },
  };
}

test('poller stays off when features.chat is not true', async () => {
  const intervals = [];
  let syncs = 0;
  assert.equal(shouldPoll(false, 'active'), false);
  assert.equal(shouldPoll(undefined, 'active'), false);
  assert.equal(shouldPoll('true', 'active'), false);
  const poller = createChatPoller({
    featuresChat: false,
    setIntervalFn: (fn, ms) => { intervals.push(ms); return 1; },
    clearIntervalFn: () => {},
    syncInbox: async () => { syncs += 1; return { rooms: [] }; },
  });
  poller.setAppState('active');
  poller.syncNow();
  await flushed();
  assert.equal(intervals.length, 0);
  assert.equal(poller.isRunning(), false);
  assert.equal(syncs, 0);
});

test('poller runs every 15s in the foreground and stops when backgrounded', async () => {
  const intervals = [];
  let tick;
  let cleared = 0;
  let syncs = 0;
  const threadArgs = [];
  const poller = createChatPoller({
    featuresChat: true,
    setIntervalFn: (fn, ms) => { tick = fn; intervals.push(ms); return 11; },
    clearIntervalFn: (id) => { cleared = id; },
    syncInbox: async () => {
      syncs += 1;
      return { rooms: [{ id: 10, unread: 3, last_message: null }] };
    },
    fetchThread: async (...args) => {
      threadArgs.push(args);
      return { messages: [{ id: 5, type: 'text', body: 'hi' }] };
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
  assert.equal(poller.getState().unread, 3);
  assert.equal(threadArgs.length, 0);

  poller.setFocusedRoomId(10);
  await flushed();
  assert.deepEqual(threadArgs, [[10]]);
  assert.equal(poller.getState().messagesByRoom[10][0].id, 5);

  poller.setAppState('inactive');
  assert.equal(cleared, 11);
  assert.equal(poller.isRunning(), false);
});

test('HTTP 429 backs off 30s and marks the UI offline', async () => {
  let clock = 0;
  let syncs = 0;
  let tick;
  let last;
  const poller = createChatPoller({
    featuresChat: true,
    now: () => clock,
    backoffMs: 30000,
    setIntervalFn: (fn) => { tick = fn; return 2; },
    clearIntervalFn: () => {},
    syncInbox: async () => {
      syncs += 1;
      throw { response: { status: 429 } };
    },
  });
  poller.subscribe((state) => { last = state; });
  poller.setAppState('active');
  await flushed();
  assert.equal(syncs, 1);
  assert.equal(last.offline, true);
  clock = 15000;
  tick();
  await flushed();
  assert.equal(syncs, 1);
  clock = 30000;
  tick();
  await flushed();
  assert.equal(syncs, 2);
});

test('mergeMessages keeps older ids and overwrites by id', () => {
  const merged = mergeMessages(
    [{ id: 1, body: 'old' }, { id: 4, body: 'queued-neighbor' }],
    [{ id: 4, body: 'available' }, { id: 7, body: 'new' }],
  );
  assert.deepEqual(merged.map((msg) => [msg.id, msg.body]), [[1, 'old'], [4, 'available'], [7, 'new']]);
});

test('in-flight thread page merges into the room it was fetched for', async () => {
  let resolveA;
  const reads = [];
  const poller = createChatPoller({
    featuresChat: true,
    setIntervalFn: () => 1,
    clearIntervalFn: () => {},
    syncInbox: async () => ({ rooms: [{ id: 1, unread: 1 }, { id: 2, unread: 1 }] }),
    fetchThread: (id) => {
      if (id === 1) return new Promise((resolve) => { resolveA = resolve; });
      return { messages: [{ id: 200, body: 'b' }] };
    },
    markRead: async (id, last) => { reads.push([id, last]); },
  });
  poller.setAppState('active');
  await flushed();
  poller.setFocusedRoomId(1);
  await flushed();
  assert.equal(typeof resolveA, 'function');
  poller.setFocusedRoomId(2);
  resolveA({ messages: [{ id: 100, body: 'a' }] });
  await flushed();
  assert.equal(poller.getState().messagesByRoom[1][0].id, 100);
  assert.equal(poller.getState().messagesByRoom[2][0].id, 200);
  assert.deepEqual(reads, [[2, 200]]);
});

test('outbox fingerprints the payload and retries the same client UUID', async () => {
  assert.equal(fingerprintPayload('text', 'На смену'), 'text\nНа смену\n');
  const expected = digest('text\nНа смену\n');
  const storage = memoryStorage();
  const sent = [];
  let clock = 1000;
  const box = createOutbox({
    storage,
    userId: 5,
    uuid: () => uuid,
    digest: async (value) => digest(value),
    now: () => clock,
    send: async (item) => {
      sent.push({ client_id: item.client_id, fingerprint: item.fingerprint, body: item.body });
      if (sent.length === 1) throw { code: 'ERR_NETWORK' };
      return { status: 201, data: { id: 42, client_id: item.client_id, status: 'available' } };
    },
  });
  await box.load();
  const clientId = await box.enqueue({ room_id: 10, type: 'text', body: 'На смену' });
  assert.equal(clientId, uuid);
  assert.equal(storage.peek().items[0].fingerprint, expected);
  assert.equal(storage.peek().items[0].client_id, uuid);
  assert.equal(storage.peek().items[0].status, 'pending');
  assert.equal(sent[0].client_id, uuid);
  assert.equal(sent[0].fingerprint, expected);
  clock += 2000;
  await box.flush();
  assert.equal(sent.length, 2);
  assert.equal(sent[1].client_id, uuid);
  assert.equal(sent[1].fingerprint, expected);
  assert.equal(sent[1].body, 'На смену');
  assert.equal(box.snapshot().items.length, 0);
});

test('outbox shows 409 as an error and does not retry with a new UUID', async () => {
  const storage = memoryStorage();
  const sent = [];
  let clock = 0;
  const box = createOutbox({
    storage,
    userId: 5,
    uuid: () => uuid,
    digest: async (value) => digest(value),
    now: () => clock,
    send: async (item) => {
      sent.push(item.client_id);
      throw { response: { status: 409, data: { message: 'Этот client_id уже использован с другим содержимым.' } } };
    },
  });
  await box.load();
  await box.enqueue({ room_id: 10, type: 'text', body: 'На смену' });
  assert.equal(box.snapshot().items[0].status, 'error');
  assert.match(box.snapshot().items[0].error, /client_id/);
  clock += 20000;
  await box.flush();
  assert.equal(sent.length, 1);
  assert.equal(sent[0], uuid);
  assert.equal(box.snapshot().items[0].httpStatus, 409);
});

test('second text enqueued during an in-flight POST is flushed without waiting for the poller', async () => {
  const storage = memoryStorage();
  const sent = [];
  let finishFirst;
  let n = 0;
  const box = createOutbox({
    storage,
    userId: 5,
    uuid: () => `00000000-0000-4000-8000-${String(++n).padStart(12, '0')}`,
    digest: async (value) => digest(value),
    send: async (item) => {
      sent.push(item.body);
      if (item.body === 'A') {
        return new Promise((resolve) => {
          finishFirst = () => resolve({ status: 201, data: { id: 1, client_id: item.client_id, status: 'available' } });
        });
      }
      return { status: 201, data: { id: sent.length, client_id: item.client_id, status: 'available' } };
    },
  });
  await box.load();
  const first = box.enqueue({ room_id: 10, type: 'text', body: 'A' });
  await flushed();
  assert.equal(sent.length, 1);
  const second = box.enqueue({ room_id: 10, type: 'text', body: 'B' });
  await flushed();
  assert.equal(storage.peek().items.length, 2);
  finishFirst();
  await first;
  await second;
  assert.deepEqual(sent, ['A', 'B']);
  assert.equal(box.snapshot().items.length, 0);
});

test('load turns 401/403 rows pending so reauth can flush the same UUID', async () => {
  const storage = memoryStorage({
    schema: 1,
    user_id: 5,
    items: [{
      client_id: uuid,
      room_id: 10,
      type: 'text',
      body: 'На смену',
      fingerprint: digest('text\nНа смену\n'),
      attempts: 1,
      status: 'error',
      httpStatus: 401,
      error: 'Сообщение не отправлено.',
      nextAttemptAt: 0,
    }],
  });
  const sent = [];
  const box = createOutbox({
    storage,
    userId: 5,
    uuid: () => uuid,
    digest: async (value) => digest(value),
    send: async (item) => {
      sent.push(item.client_id);
      return { status: 201, data: { id: 9, client_id: item.client_id, status: 'available' } };
    },
  });
  await box.load();
  assert.equal(box.snapshot().items[0].status, 'pending');
  await box.flush();
  assert.equal(sent[0], uuid);
  assert.equal(box.snapshot().items.length, 0);
});
