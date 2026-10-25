const { test } = require('node:test');
const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');
const { createChatPoller, shouldPoll, mergeMessages, canManageMembers, POLL_MS } = require('../src/chatPoller');
const { createOutbox, fingerprintPayload, uploadHeld } = require('../src/chatOutbox');

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

test('attachment fingerprint includes file sha256', () => {
  assert.equal(fingerprintPayload('image', '', 'deadbeef'), 'image\n\ndeadbeef');
  assert.equal(fingerprintPayload('pdf', 'подпись', 'abc'), 'pdf\nподпись\nabc');
  assert.equal(digest(fingerprintPayload('image', '', 'deadbeef')), digest('image\n\ndeadbeef'));
});

test('HTTP 202 keeps the attachment queued and does not re-POST', async () => {
  const storage = memoryStorage();
  const sent = [];
  let acks = 0;
  let clock = 0;
  const box = createOutbox({
    storage,
    userId: 5,
    uuid: () => uuid,
    digest: async (value) => digest(value),
    now: () => clock,
    onAck: () => { acks += 1; },
    send: async (item) => {
      sent.push({ client_id: item.client_id, localUri: item.localUri });
      return { status: 202, data: { id: 11, client_id: item.client_id, status: 'queued' } };
    },
  });
  await box.load();
  await box.enqueue({
    room_id: 10,
    type: 'image',
    body: '',
    localUri: 'file:///tmp/photo.jpg',
    fileName: 'photo.jpg',
    mime: 'image/jpeg',
    fileSha256: 'deadbeef',
  });
  assert.equal(sent.length, 1);
  assert.equal(sent[0].localUri, 'file:///tmp/photo.jpg');
  assert.equal(box.snapshot().items.length, 1);
  assert.equal(box.snapshot().items[0].status, 'queued');
  assert.equal(box.snapshot().items[0].localUri, undefined);
  assert.equal(box.snapshot().items[0].client_id, uuid);
  assert.equal(box.snapshot().items[0].fingerprint, digest('image\n\ndeadbeef'));
  assert.equal(acks, 0);
  clock += 20000;
  await box.flush();
  assert.equal(sent.length, 1);
  assert.equal(box.snapshot().items[0].status, 'queued');
});

test('replay 200 with queued also holds and 200 rejected drops the item', async () => {
  const storage = memoryStorage();
  const sent = [];
  let n = 0;
  const box = createOutbox({
    storage,
    userId: 5,
    uuid: () => `00000000-0000-4000-8000-${String(++n).padStart(12, '0')}`,
    digest: async (value) => digest(value),
    send: async (item) => {
      sent.push(item.type);
      if (item.type === 'image') {
        return { status: 200, data: { id: 3, client_id: item.client_id, status: 'queued' } };
      }
      return { status: 200, data: { id: 4, client_id: item.client_id, status: 'rejected' } };
    },
  });
  await box.load();
  await box.enqueue({ room_id: 10, type: 'image', body: '', fileSha256: 'aa' });
  assert.equal(box.snapshot().items.length, 1);
  assert.equal(box.snapshot().items[0].status, 'queued');
  await box.enqueue({ room_id: 10, type: 'pdf', body: '', fileSha256: 'bb' });
  assert.equal(sent.length, 2);
  assert.equal(box.snapshot().items.length, 1);
  assert.equal(box.snapshot().items[0].type, 'image');
});

test('uploadHeld stays private to the outbox module', () => {
  assert.equal(uploadHeld, undefined);
});

test('poller ACKs outbox when the thread shows available or rejected', async () => {
  const storage = memoryStorage();
  const rejectedId = '11111111-1111-4111-8111-111111111111';
  const availableId = '22222222-2222-4222-8222-222222222222';
  let n = 0;
  const ids = [rejectedId, availableId];
  const box = createOutbox({
    storage,
    userId: 5,
    uuid: () => ids[n++] || crypto.randomUUID(),
    digest: async (value) => digest(value),
    send: async (item) => ({ status: 202, data: { id: item.body === 'r' ? 8 : 9, client_id: item.client_id, status: 'queued' } }),
  });
  await box.load();
  await box.enqueue({ room_id: 10, type: 'image', body: 'r', fileSha256: 'r1' });
  await box.enqueue({ room_id: 10, type: 'pdf', body: 'a', fileSha256: 'a1' });
  assert.equal(box.snapshot().items.length, 2);

  const poller = createChatPoller({
    featuresChat: true,
    userId: 5,
    setIntervalFn: () => 1,
    clearIntervalFn: () => {},
    syncInbox: async () => ({ rooms: [{ id: 10, unread: 0, can_manage_members: true }] }),
    fetchThread: async () => ({
      messages: [
        { id: 8, client_id: rejectedId, status: 'rejected' },
        { id: 9, client_id: availableId, status: 'available', type: 'pdf' },
      ],
    }),
    ackOutbox: (messages) => box.ack(messages),
  });
  poller.setAppState('active');
  await flushed();
  poller.setFocusedRoomId(10);
  await flushed();
  assert.equal(box.snapshot().items.length, 0);
  assert.equal(poller.getState().messagesByRoom[10].find((msg) => msg.client_id === rejectedId).status, 'rejected');
  assert.equal(canManageMembers(poller.getState().rooms[0]), true);
});

test('thread snapshot contains client_id before ackOutbox resolves', async () => {
  const clientId = '33333333-3333-4333-8333-333333333333';
  const storage = memoryStorage();
  const box = createOutbox({
    storage,
    userId: 5,
    uuid: () => clientId,
    digest: async (value) => digest(value),
    send: async (item) => ({ status: 202, data: { id: 12, client_id: item.client_id, status: 'queued' } }),
  });
  await box.load();
  await box.enqueue({ room_id: 10, type: 'image', body: '', fileSha256: 'cc' });
  assert.equal(box.snapshot().items[0].status, 'queued');

  let ackStarted = false;
  let ackResolved = false;
  let listenerSawClientBeforeAck = false;
  let resolveAck;
  const poller = createChatPoller({
    featuresChat: true,
    userId: 5,
    setIntervalFn: () => 1,
    clearIntervalFn: () => {},
    syncInbox: async () => ({ rooms: [{ id: 10, unread: 1 }] }),
    fetchThread: async () => ({
      messages: [{ id: 12, client_id: clientId, status: 'available', type: 'image' }],
    }),
    ackOutbox: (messages) => {
      ackStarted = true;
      return new Promise((resolve) => {
        resolveAck = async () => {
          await box.ack(messages);
          ackResolved = true;
          resolve();
        };
      });
    },
    markRead: async () => {},
  });
  poller.subscribe((state) => {
    const has = (state.messagesByRoom[10] || []).some((msg) => msg.client_id === clientId);
    if (has && !ackResolved) listenerSawClientBeforeAck = true;
  });
  poller.setAppState('active');
  await flushed();
  poller.setFocusedRoomId(10);
  await flushed();
  assert.equal(ackStarted, true);
  assert.equal(ackResolved, false);
  assert.equal(listenerSawClientBeforeAck, true);
  assert.equal(box.snapshot().items.length, 1);
  await resolveAck();
  await flushed();
  assert.equal(ackResolved, true);
  assert.equal(box.snapshot().items.length, 0);
});

test('mergeThread ACKs available and rejected client_ids', async () => {
  const storage = memoryStorage();
  const rejectedId = '44444444-4444-4444-8444-444444444444';
  const availableId = '55555555-5555-4555-8555-555555555555';
  let n = 0;
  const ids = [rejectedId, availableId];
  const box = createOutbox({
    storage,
    userId: 5,
    uuid: () => ids[n++] || crypto.randomUUID(),
    digest: async (value) => digest(value),
    send: async (item) => ({ status: 202, data: { id: item.body === 'r' ? 8 : 9, client_id: item.client_id, status: 'queued' } }),
  });
  await box.load();
  await box.enqueue({ room_id: 10, type: 'image', body: 'r', fileSha256: 'r2' });
  await box.enqueue({ room_id: 10, type: 'pdf', body: 'a', fileSha256: 'a2' });
  assert.equal(box.snapshot().items.length, 2);

  const poller = createChatPoller({
    featuresChat: true,
    userId: 5,
    setIntervalFn: () => 1,
    clearIntervalFn: () => {},
    syncInbox: async () => ({ rooms: [] }),
    ackOutbox: (messages) => box.ack(messages),
  });
  await poller.mergeThread(10, [
    { id: 8, client_id: rejectedId, status: 'rejected' },
    { id: 9, client_id: availableId, status: 'available', type: 'pdf' },
  ]);
  assert.equal(box.snapshot().items.length, 0);
  assert.equal(poller.getState().messagesByRoom[10].find((msg) => msg.client_id === rejectedId).status, 'rejected');
});

test('rejected stub without sender_id is treated as own message', () => {
  const src = fs.readFileSync(path.join(__dirname, '../src/screens/ChatThreadScreen.js'), 'utf8');
  assert.match(src, /item\.status === 'rejected' \|\| \(item\.sender_id === userId && item\.type !== 'system'\)/);
  const mine = (item, userId) => item.status === 'rejected' || (item.sender_id === userId && item.type !== 'system');
  const stub = { id: 8, client_id: '11111111-1111-4111-8111-111111111111', status: 'rejected' };
  assert.equal(mine(stub, 5), true);
  assert.equal(mine({ id: 9, sender_id: 5, type: 'text' }, 5), true);
  assert.equal(mine({ id: 10, sender_id: 7, type: 'text' }, 5), false);
  assert.equal(mine({ id: 11, sender_id: 5, type: 'system' }, 5), false);
});

test('composer caption stays until enqueueFile succeeds', () => {
  const src = fs.readFileSync(path.join(__dirname, '../src/screens/ChatThreadScreen.js'), 'utf8');
  const pickPhoto = src.slice(src.indexOf('const pickPhoto'), src.indexOf('const pickPdf'));
  const pickPdf = src.slice(src.indexOf('const pickPdf'), src.indexOf('const openPdf'));
  assert.ok(pickPhoto.indexOf("setText('')") > pickPhoto.indexOf('await enqueueFile'));
  assert.ok(pickPdf.indexOf("setText('')") > pickPdf.indexOf('await enqueueFile'));
});

test('manage UI follows can_manage_members from inbox JSON', async () => {
  assert.equal(canManageMembers({ can_manage_members: true }), true);
  assert.equal(canManageMembers({ can_manage_members: false }), false);
  assert.equal(canManageMembers({}), false);
  assert.equal(canManageMembers(null), false);
  const poller = createChatPoller({
    featuresChat: true,
    setIntervalFn: () => 1,
    clearIntervalFn: () => {},
    syncInbox: async () => ({
      rooms: [
        { id: 1, unread: 0, can_manage_members: true },
        { id: 2, unread: 0, can_manage_members: false },
      ],
    }),
  });
  poller.setAppState('active');
  await flushed();
  assert.equal(poller.getState().rooms[0].can_manage_members, true);
  assert.equal(canManageMembers(poller.getState().rooms[1]), false);
  const membersSrc = fs.readFileSync(path.join(__dirname, '../src/screens/ChatMembersScreen.js'), 'utf8');
  assert.match(membersSrc, /can_manage_members/);
  assert.match(membersSrc, /canManageMembers/);
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
