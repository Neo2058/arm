const { test } = require('node:test');
const assert = require('node:assert/strict');
const { createPending } = require('../src/instructions/pending.cjs');
function fixture(send) {
 let saved = null, active = true, writes = 0;
 const storage = { assertActive() { if (!active) throw Error('logout'); }, read: async () => saved, write: async s => { saved = JSON.parse(JSON.stringify(s)); writes++; } };
 return { create: () => createPending({ storage,send }), saved: () => saved, writes: () => writes, logout() { active = false; saved = null; } };
}
test('uncertain confirmation persists before send and exact retry survives restart', async () => {
 let f; const sent=[];
 f=fixture(async p => { assert.ok(f.saved().entries.editor.pending); sent.push(p); if(sent.length===1)throw Error('network'); return {document_id:7,signed:true,signed_at:'2026-11-12T12:00:00Z'}; });
 const first=f.create();await first.load();assert.equal(await first.confirm({id:7,title:'Правила'}),false);
 await assert.rejects(first.confirm({id:8,title:'Другой'}));await first.stop();
 const second=f.create();await second.load();assert.equal(await second.retry(),true);assert.deepEqual(sent[0],sent[1]);assert.equal(second.snapshot().pending,null);
});
test('failed persistence prevents the request',async()=>{
 let sent=false;
 const m=createPending({storage:{assertActive(){},write:async()=>{throw Error('disk');}},send:async()=>{sent=true;}});
 await assert.rejects(m.confirm({id:1,title:'x'}));assert.equal(sent,false);
});
test('uncertain response cannot be dismissed, explicit rejection can',async()=>{
 let status=null;const f=fixture(async()=>{throw {response:status?{status}:undefined};});const m=f.create();
 await m.confirm({id:1,title:'x'});await assert.rejects(m.dismissRejected());status=403;await m.retry();await m.dismissRejected();assert.equal(m.snapshot().pending,null);
});
test('logout blocks late writes',async()=>{
 let resolve;const f=fixture(()=>new Promise(r=>{resolve=r;}));const m=f.create();const request=m.confirm({id:1,title:'x'});
 while(!resolve)await new Promise(r=>setImmediate(r));const count=f.writes();f.logout();resolve({document_id:1,signed:true,signed_at:'2026-11-12'});
 await assert.rejects(request);assert.equal(f.writes(),count);assert.equal(f.saved(),null);
});
test('wrong document response cannot clear pending confirmation',async()=>{
 const f=fixture(async()=>({document_id:2,signed:true,signed_at:'2026-11-12'}));const m=f.create();
 assert.equal(await m.confirm({id:1,title:'x'}),false);assert.equal(m.snapshot().pending.id,1);
});
