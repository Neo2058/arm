const { test } = require('node:test');
const assert = require('node:assert/strict');
const { canSubmitPodstroiki, createPodstroikiMutations, normalizeSubmit } = require('../src/podstroiki/mutations.cjs');

function setup(send, saved = null) {
  let disk = saved;
  const store = {
    read: async () => disk && structuredClone(disk),
    write: async (value) => { disk = structuredClone(value); },
  };
  let n = 0;
  const queue = createPodstroikiMutations({
    storage: store,
    uuid: () => `aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa${n++}`,
    send,
  });
  return { queue, disk: () => disk };
}

test('drivers instructors and admins submit podstroiki; dispatchers do not', () => {
  assert.equal(canSubmitPodstroiki({ role: 'driver' }), true);
  assert.equal(canSubmitPodstroiki({ role: 'instructor' }), true);
  assert.equal(canSubmitPodstroiki({ role: 'admin' }), true);
  assert.equal(canSubmitPodstroiki({ role: 'super_admin' }), true);
  assert.equal(canSubmitPodstroiki({ role: 'dispatcher' }), false);
  assert.equal(canSubmitPodstroiki({ role: 'operator' }), false);
});

test('normalize rejects empty date route shift and details', () => {
  assert.throws(() => normalizeSubmit({}), /дату/);
  assert.throws(() => normalizeSubmit({ target_date: '2026-11-10' }), /маршрут/);
  assert.throws(() => normalizeSubmit({ target_date: '2026-11-10', route_code: '01' }), /смену/);
  assert.throws(() => normalizeSubmit({ target_date: '2026-11-10', route_code: '01', shift_code: '1' }), /подстроить/);
  assert.deepEqual(
    normalizeSubmit({ target_date: '2026-11-10', route_code: ' 01 ', shift_code: '2', details: '  ночь  ' }),
    { target_date: '2026-11-10', route_code: '01', shift_code: '2', details: 'ночь' }
  );
});

test('persists exact UUID before send and retries after restart without a new key', async () => {
  let pending;
  const first = setup(async (request) => {
    pending = request;
    assert.deepEqual(first.disk().entries.editor.pending, request);
    throw new Error('network');
  });
  await first.queue.open({ target_date: '2026-11-10', route_code: '01', shift_code: '1', details: 'смена' });
  await first.queue.submit(first.queue.snapshot().draft.fields);
  assert.ok(first.queue.snapshot().pending);
  assert.equal(first.queue.snapshot().pending.input.operation_id, 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa0');
  const second = setup(async (request) => {
    assert.deepEqual(request, pending);
    return { success: true, podstroika: { id: 9 } };
  }, first.disk());
  await second.queue.load();
  await second.queue.retry();
  assert.equal(second.queue.snapshot().pending, null);
  assert.equal(second.queue.snapshot().result.podstroika.id, 9);
});

test('422 unlocks submit; uncertain outcomes stay frozen', async () => {
  const invalid = setup(async () => {
    throw { response: { status: 422, data: { message: 'Нельзя подать заявку на прошедшую дату.' } } };
  });
  await invalid.queue.open({ target_date: '2026-11-10', route_code: '01', shift_code: '1', details: 'смена' });
  await invalid.queue.submit(invalid.queue.snapshot().draft.fields);
  assert.match(invalid.queue.snapshot().error, /прошедшую дату/);
  await invalid.queue.unlock();
  await invalid.queue.edit({ target_date: '2026-11-12' });
  assert.equal(invalid.queue.snapshot().draft.fields.target_date, '2026-11-12');

  const lost = setup(async () => { throw new Error('network'); });
  await lost.queue.open({ target_date: '2026-11-10', route_code: '01', shift_code: '1', details: 'смена' });
  await lost.queue.submit(lost.queue.snapshot().draft.fields);
  await assert.rejects(lost.queue.unlock());
  await assert.rejects(lost.queue.open({}));
});

test('cancel persists the same id and write failure prevents HTTP', async () => {
  let sent;
  const ok = setup(async (request) => {
    sent = request;
    return { success: true, message: 'отозвана' };
  });
  await ok.queue.cancel(44);
  assert.deepEqual(sent, { action: 'cancel', id: '44', input: {} });
  assert.equal(ok.queue.snapshot().pending, null);

  let sends = 0;
  const blocked = createPodstroikiMutations({
    storage: { read: async () => null, write: async () => { throw new Error('disk'); } },
    uuid: () => 'uuid',
    send: async () => { sends++; },
  });
  await assert.rejects(blocked.submit({ target_date: '2026-11-10', route_code: '01', shift_code: '1', details: 'смена' }));
  assert.equal(sends, 0);
});

test('stop blocks late success from clearing the frozen request', async () => {
  let finish;
  const x = setup(() => new Promise((resolve) => { finish = resolve; }));
  await x.queue.open({ target_date: '2026-11-10', route_code: '01', shift_code: '1', details: 'смена' });
  const sending = x.queue.submit(x.queue.snapshot().draft.fields);
  while (!finish) await new Promise((resolve) => setImmediate(resolve));
  await x.queue.stop();
  const before = structuredClone(x.disk());
  finish({ success: true, podstroika: { id: 1 } });
  await sending;
  assert.deepEqual(x.disk(), before);
});
