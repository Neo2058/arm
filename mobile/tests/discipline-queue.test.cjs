const test = require('node:test');
const assert = require('node:assert/strict');
const { createQueue } = require('../src/discipline/queue.cjs');
const op = '11111111-1111-4111-8111-111111111111';
const info = { assignment: { id: '1', version: '9007199254740993', status: 'assigned', requirements: { require_response: true } }, reports: [] };
const clone = x => JSON.parse(JSON.stringify(x));
function fixture(send) {
  let disk = null, fail = false, clock = 1000;
  const storage = { read: async () => clone(disk), write: async value => { if (fail) throw new Error('Disk full'); disk = clone(value); }, verify: async () => {}, importFiles: async files => clone(files) };
  const make = () => createQueue({ storage, send, uuid: () => op, now: () => clock });
  return { make, disk: () => disk, fail: value => { fail = value; }, tick: () => { clock += 300001; } };
}
async function prepare(q) { await q.load(); await q.cache(info); await q.edit('1', 'Report'); }

test('draft and cached card survive process restart before any submission', async () => {
  const f = fixture(async () => assert.fail('Draft must not send')); const q = f.make(); await prepare(q);
  await q.addFiles('1', [{ id: 'f', uri: 'private/file.pdf', name: 'file.pdf', mime: 'application/pdf', size: 1, category: 'report' }]);
  await q.stop(); const restarted = f.make(); await restarted.load(); await restarted.flush();
  assert.equal(restarted.snapshot().entries['1'].response, 'Report'); assert.equal(restarted.snapshot().entries['1'].files.length, 1);
});
test('lost acknowledgement restarts with identical UUID/version/text, then clears only after durable receipt', async () => {
  const requests = []; let first = true;
  const f = fixture(async snapshot => { requests.push(clone(snapshot)); if (first) { first = false; throw new Error('Response lost after commit'); } return { ...info, assignment: { ...info.assignment, status: 'in_review' } }; });
  const q = f.make(); await prepare(q); await q.enqueue('1'); await q.flush(); assert.equal(q.snapshot().entries['1'].status, 'queued');
  await assert.rejects(q.edit('1', 'Different')); await q.stop(); f.tick();
  const restarted = f.make(); await restarted.load(); await restarted.flush();
  assert.deepEqual(requests[0], requests[1]); assert.equal(requests[1].version, '9007199254740993'); assert.equal(restarted.snapshot().entries['1'].pending, null); assert.equal(f.disk().entries['1'].status, 'sent');
});
test('storage failure prevents new submission from reaching server', async () => {
  let count = 0; const f = fixture(async () => { count++; }); const q = f.make(); await prepare(q); f.fail(true);
  await assert.rejects(q.enqueue('1')); await q.flush(); assert.equal(count, 0); assert.equal(f.disk().entries['1'].pending, null);
});
test('conflicts stop automatic retries and require explicit correction', async () => {
  let count = 0; const f = fixture(async () => { count++; throw { response: { status: 412 } }; }); const q = f.make(); await prepare(q); await q.enqueue('1'); await q.flush(); f.tick(); await q.flush();
  assert.equal(count, 1); assert.equal(q.snapshot().entries['1'].status, 'blocked'); await q.unlock('1'); assert.equal(q.snapshot().entries['1'].response, 'Report'); assert.equal(q.snapshot().entries['1'].pending, null);
});
test('revoked authentication blocks retries without losing the frozen operation', async () => {
  let count = 0; const f = fixture(async () => { count++; throw { response: { status: 401 } }; }); const q = f.make(); await prepare(q); await q.enqueue('1'); await q.flush(); f.tick(); await q.flush();
  assert.equal(count, 1); assert.equal(q.snapshot().entries['1'].pending.operation_id, op); await assert.rejects(q.unlock('1'));
});
test('concurrent flushes submit once; stopping prevents late acknowledgement from rewriting storage', async () => {
  let finish, count = 0; const f = fixture(() => { count++; return new Promise(resolve => { finish = resolve; }); }); const q = f.make(); await prepare(q); await q.enqueue('1');
  const running = q.flush(); await new Promise(resolve => setImmediate(resolve)); await q.flush(); await q.stop(); finish(info); await running;
  assert.equal(count, 1); assert.equal(f.disk().entries['1'].pending.operation_id, op);
});
test('missing private file suspends operation instead of silently sending a changed request', async () => {
  let sends = 0, disk;
  const q = createQueue({ storage: { read: async () => null, write: async s => { disk = clone(s); }, verify: async () => { throw { code: 'LOCAL_FILE_MISSING' }; } }, send: async () => { sends++; }, uuid: () => op });
  await prepare(q); await q.enqueue('1'); await q.flush(); assert.equal(sends, 0); assert.equal(disk.entries['1'].status, 'blocked'); assert.equal(disk.entries['1'].pending.operation_id, op);
});
test('late refresh cannot overwrite a newer submission or review version', async () => {
  const f = fixture(async () => info); const q = f.make(); await prepare(q);
  await q.cache({ ...info, assignment: { ...info.assignment, version: '9007199254740994', status: 'accepted' } });
  await q.cache(info); assert.equal(q.snapshot().entries['1'].info.assignment.status, 'accepted');
});
