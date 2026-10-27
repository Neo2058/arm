import test from 'node:test';
import assert from 'node:assert/strict';
import { createEditor, requestFor, settingsDraft, shiftDraft, monthDays, moveMonth } from '../src/worktime/model.mjs';

const copy = value => JSON.parse(JSON.stringify(value));
const config = (revision = 0) => ({ month: '2026-10', revision, settings: { line_rate_kopecks: 57348, reserve_rate_kopecks: 55300, surface_rate_kopecks: 51204, evening_percent: 20, night_percent: 40, rest_dates: [] } });
const failure = (status, errors) => Object.assign(new Error('failed'), { response: { status, data: { errors } } });
function fixture(send = async () => ({ shift: { id: 1 } })) {
  let disk = null, failWrite = false, count = 0;
  const requests = [], events = [];
  const storage = { read: async () => copy(disk), write: async value => { if (failWrite) throw new Error('Disk full'); disk = copy(value); events.push('persist'); } };
  const editor = () => createEditor({ storage, uuid: () => `uuid-${++count}`, send: async request => { events.push('send'); requests.push(copy(request)); return send(request); } });
  return { editor, storage, requests, events, failWrite: value => { failWrite = value; } };
}
test('uncertain response survives restart with exact UUID, version and body', async () => {
  let offline = true;
  const f = fixture(async () => { if (offline) throw new Error('timeout'); return { shift: { id: 19 } }; });
  const first = f.editor(); await first.load();
  await first.open(shiftDraft({ id: 19, revision: 7, started_at: '2026-10-27 08:00', ended_at: '2026-10-27 16:00' }));
  await first.submit(); const frozen = first.snapshot().pending;
  assert.equal(f.events.at(-2), 'send'); // error state is persisted after the send
  assert.ok(f.events.indexOf('persist') < f.events.indexOf('send'));
  await assert.rejects(first.edit({ ended_at: '2026-10-27 18:00' }));
  await assert.rejects(first.close()); await assert.rejects(first.unlock());
  await first.stop();
  const second = f.editor(); await second.load(); offline = false; await second.retry();
  assert.deepEqual(f.requests, [frozen, frozen]);
  assert.equal(second.snapshot().result.shift.id, 19); assert.equal(second.snapshot().pending, null);
});
test('storage failure prevents sending and creating a frozen in-memory request', async () => {
  const f = fixture(), editor = f.editor(); await editor.open(shiftDraft(null, '2026-10-27'));
  f.failWrite(true); await assert.rejects(editor.submit(), /Disk full/);
  assert.equal(f.requests.length, 0); assert.equal(editor.snapshot().pending, null);
  f.failWrite(false); await editor.submit(); assert.equal(f.requests.length, 1);
});
test('lost acknowledgement on device after success retries the original request', async () => {
  let f;
  f = fixture(async () => { f.failWrite(true); return { shift: { id: 4 } }; });
  const a = f.editor(); await a.open(shiftDraft(null, '2026-10-27'));
  await assert.rejects(a.submit(), /Disk full/); const request = a.snapshot().pending;
  assert.ok(request); f.failWrite(false);
  const b = f.editor(); await b.load(); assert.deepEqual(b.snapshot().pending, request);
});
test('409 requires comparison then keeps input and adopts explicit current revision', async () => {
  let conflict = true;
  const f = fixture(async () => { if (conflict) throw failure(409); return { shift: { id: 2 } }; });
  const editor = f.editor();
  await editor.open(shiftDraft({ id: 2, revision: 1, started_at: '2026-10-27 08:00', ended_at: '2026-10-27 16:00' }));
  await editor.edit({ ended_at: '2026-10-27 17:00' }); await editor.submit();
  await assert.rejects(editor.unlock(), /загрузите/);
  await editor.setCurrent({ shift: { id: 2, revision: 2 } }); await editor.unlock();
  assert.equal(editor.snapshot().draft.values.ended_at, '2026-10-27 17:00');
  conflict = false; await editor.submit();
  assert.equal(f.requests[1].data.expected_revision, 2);
  assert.notEqual(f.requests[0].data.operation_id, f.requests[1].data.operation_id);
});
test('settings conflict reapplies only intended fields, preserving concurrent settings', async () => {
  const f = fixture(async () => { throw failure(409); }), editor = f.editor();
  await editor.open(settingsDraft(config())); await editor.edit({ line_rate_kopecks: '600,01' }); await editor.submit();
  const latest = config(1); latest.settings.night_percent = 45;
  await editor.setCurrent(latest); await editor.unlock();
  const op = requestFor(editor.snapshot().draft);
  assert.deepEqual(op.data, { expected_revision: 1, settings: { line_rate_kopecks: 60001 } });
  assert.equal(editor.snapshot().draft.values.night_percent, '45');
});
test('validation refusal unlocks input; auth and rate limits preserve frozen request', async () => {
  for (const status of [422, 401, 403, 429, 500]) {
    const f = fixture(async () => { throw failure(status, { started_at: ['Пересечение смен.'] }); }), editor = f.editor();
    await editor.open(shiftDraft(null, '2026-10-27')); await editor.submit();
    if (status === 422) { await editor.unlock(); assert.ok(editor.snapshot().draft); assert.equal(editor.snapshot().pending, null); }
    else { await assert.rejects(editor.unlock()); assert.ok(editor.snapshot().pending); }
  }
});
test('parallel submission shares the frozen operation and sends once while active', async () => {
  let finish;
  const f = fixture(() => new Promise(resolve => { finish = resolve; })), editor = f.editor();
  await editor.open(shiftDraft(null, '2026-10-27'));
  const a = editor.submit(), b = editor.submit();
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(f.requests.length, 1); finish({ shift: { id: 8 } }); await Promise.all([a, b]);
});
test('two account storage adapters keep drafts separate', async () => {
  const a = fixture(), b = fixture(); await a.editor().open(shiftDraft(null, '2026-10-27'));
  const other = b.editor(); await other.load(); assert.equal(other.snapshot().draft, null);
});
test('money uses integer kopecks and rejects ambiguous decimal input', () => {
  const draft = settingsDraft(config()); draft.values.reserve_rate_kopecks = '553,09';
  assert.deepEqual(requestFor(draft).data.settings, { reserve_rate_kopecks: 55309 });
  draft.values.reserve_rate_kopecks = '553.009'; assert.throws(() => requestFor(draft));
  assert.throws(() => requestFor(settingsDraft(config())), /Нет изменённых/);
});
test('local midnight, segment boundaries and paid breaks are sent unchanged', () => {
  const draft = shiftDraft(null, '2026-10-31'); Object.assign(draft.values, {
    started_at: '2026-10-31 22:00', ended_at: '2026-11-01 06:00', break_duration: '60',
    segments: [{ kind: 'reserve', started_at: '2026-10-31 22:00', ended_at: '2026-11-01 06:00' }],
  });
  const op = requestFor(draft);
  assert.equal(op.month, '2026-10'); assert.equal(op.data.shift_date, '2026-10-31');
  assert.equal(op.data.break_duration, 60); assert.equal(op.data.ended_at, '2026-11-01 06:00');
  assert.equal(op.data.segments[0].kind, 'reserve');
  assert.equal(monthDays('2028-02').length, 29); assert.equal(moveMonth('2026-12', 1), '2027-01');
  draft.values.started_at = '2026-10-31T22:00Z'; assert.throws(() => requestFor(draft));
});
test('stopping the editor before a queued retry prevents a new send', async () => {
  const f = fixture(async () => { throw new Error('timeout'); }), editor = f.editor();
  await editor.open(shiftDraft(null, '2026-10-27')); await editor.submit();
  await editor.stop(); await editor.retry(); assert.equal(f.requests.length, 1);
});
