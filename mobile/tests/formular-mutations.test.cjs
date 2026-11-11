const { test } = require('node:test');
const assert = require('node:assert/strict');
const { createFormularMutations } = require('../src/formular/mutations.cjs');
const clone = x => JSON.parse(JSON.stringify(x));
function fixture(send, saved = null) {
  let persisted = saved, writes = 0, active = true;
  const storage = { assertActive() { if (!active) throw Error('logout'); }, read: async () => persisted, write: async s => { writes++; persisted = clone(s); } };
  const create = () => createFormularMutations({ storage, send, uuid: () => 'fixed-uuid' });
  return { create, saved: () => clone(persisted), writes: () => writes, logout: () => { active = false; persisted = null; } };
}
test('exact request persists before send; uncertain retry survives restart', async () => {
  const sent = []; let f;
  f = fixture(async p => { assert.ok(f.saved().entries.editor.pending); sent.push(clone(p)); if (sent.length === 1) throw Error('network'); return { log: { id: 3 } }; });
  const first = f.create(); await first.load(); await first.open({ id: 5, version: 0, action: 'submit', text: 'Запись' });
  assert.equal(await first.submit(), false); await assert.rejects(first.edit('other')); await first.stop();
  const second = f.create(); await second.load(); assert.equal(await second.retry(), true); assert.deepEqual(sent[0], sent[1]); assert.equal(second.snapshot().pending, null);
});
test('failed persistence does not send anything', async () => {
  let sent = false;
  const m = createFormularMutations({ storage: { assertActive() {}, read: async () => null, write: async () => { throw Error('disk'); } }, uuid: () => 'id', send: async () => { sent = true; } });
  await assert.rejects(m.open({ id: 1, version: 0, action: 'submit', text: 'x' })); await assert.rejects(m.submit()); assert.equal(sent, false);
});
test('conflict preserves text and requires explicit correction', async () => {
  const f = fixture(async () => { throw { response: { status: 409 } }; }); const m = f.create();
  await m.open({ id: 1, version: 1, action: 'returned', text: 'Уточните' }); await m.submit();
  assert.equal(m.snapshot().draft.text, 'Уточните'); await assert.rejects(m.edit('new')); await m.correct(); await m.edit('new'); assert.equal(m.snapshot().draft.text, 'new');
});
test('uncertain network result cannot discard frozen operation', async () => {
  const f = fixture(async () => { throw Error('timeout'); }); const m = f.create();
  await m.open({ id: 1, version: 1, action: 'confirmed', text: '' }); await m.submit();
  await assert.rejects(m.correct()); await assert.rejects(m.close()); assert.equal(m.snapshot().pending.body.decision, 'confirmed');
});
test('logout epoch prevents late response writes', async () => {
  let resolve; const f = fixture(() => new Promise(r => { resolve = r; })); const m = f.create();
  await m.open({ id: 1, version: 0, action: 'submit', text: 'private' }); const pending = m.submit();
  while (!resolve) await new Promise(r => setImmediate(r)); const count = f.writes(); f.logout(); resolve({ log: { id: 1 } });
  await assert.rejects(pending); assert.equal(f.writes(), count); assert.equal(f.saved(), null);
});
test('double tap sends one in-flight request and same UUID', async () => {
  let resolve, count = 0; const f = fixture(() => { count++; return new Promise(r => { resolve = r; }); }); const m = f.create();
  await m.open({ id: 1, version: 0, action: 'submit', text: 'x' }); const first = m.submit();
  while (!resolve) await new Promise(r => setImmediate(r)); await m.submit(); assert.equal(count, 1); resolve({ log: { id: 1 } }); await first;
});
