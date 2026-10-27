const test = require('node:test');
const assert = require('node:assert/strict');
const { createMutations } = require('../src/journal/mutations.cjs');
function fixture(send) {
  let disk = null, fail = false;
  const storage = { read: async () => disk && structuredClone(disk), write: async state => { if (fail) throw Error('full'); disk = structuredClone(state); } };
  return { make: () => createMutations({ storage, send, uuid: () => 'a6d0c8b5-1d31-4bd2-b2c6-21c3a5050550' }), fail: () => { fail = true; } };
}
test('draft and uncertain creation survive restart with exact request identity', async () => {
  const calls = []; let first = true;
  const f = fixture(async request => { calls.push(structuredClone(request)); if (first) { first = false; throw Error('lost ack'); } return { task: { id: '1', version: '1' } }; });
  const q = f.make();await q.load();await q.open();await q.edit({ title: 'Report' });await q.submit('create');assert.ok(q.snapshot().pending);
  await assert.rejects(q.edit({ title: 'Different' }));await q.stop();const next = f.make();await next.load();await next.retry();assert.deepEqual(calls[0],calls[1]);assert.equal(next.snapshot().pending,null);
});
test('disk failure prevents submission', async () => {
  let calls = 0; const f = fixture(async () => { calls++; });const q=f.make();await q.load();await q.open();await q.edit({title:'X'});f.fail();await assert.rejects(q.submit('create'));assert.equal(calls,0);
});
test('conflict preserves edit and adopts fresh version only after explicit correction',async()=>{
 const f=fixture(async()=>{throw {response:{status:412,data:{current:{id:'3',version:'9007199254740994'}}}};});const q=f.make();await q.load();await q.open({id:'3',version:'9007199254740993',title:'Old'});await q.edit({title:'Mine'});await q.submit('update');assert.equal(q.snapshot().pending.input.version,'9007199254740993');await q.unlock();assert.equal(q.snapshot().draft.title,'Mine');assert.equal(q.snapshot().draft.task.version,'9007199254740994');
});
test('concurrent retries send once, logout stops late receipt writes',async()=>{
 let done,calls=0;const f=fixture(()=>{calls++;return new Promise(resolve=>{done=resolve;});});const q=f.make();await q.load();await q.open();await q.edit({title:'X'});const sending=q.submit('create');await new Promise(setImmediate);await q.retry();await q.stop();done({task:{id:'1'}});await sending;assert.equal(calls,1);assert.ok(q.snapshot().pending);
});
