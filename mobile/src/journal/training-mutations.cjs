function createTrainingMutations({ storage, send, uuid }) {
 let state={schema:1,entries:{editor:{files:[],draft:null,pending:null,result:null,error:'',status:null}}}, chain=Promise.resolve(), stopped=false,busy=false;
 const listeners=new Set(), clone=value=>JSON.parse(JSON.stringify(value));
 const change=fn=>{const p=chain.then(async()=>{if(stopped)return;const next=clone(state);fn(next.entries.editor);await storage.write(next);state=next;listeners.forEach(fn=>fn());});chain=p.catch(()=>{});return p;};
 return {
  async load(){const saved=await storage.read();if(saved)state=saved;},
  snapshot(){return clone(state.entries.editor);},subscribe(fn){listeners.add(fn);return()=>listeners.delete(fn);},
  open(draft){return change(e=>{if(e.pending)throw Error('Сначала завершите сохранённую отправку.');e.draft=clone(draft);e.result=null;e.error='';});},
  edit(fields){return change(e=>{if(e.pending)throw Error('Запрос ожидает подтверждения.');if(!e.draft)throw Error('Нет открытой формы.');Object.assign(e.draft.fields,clone(fields));});},
  close(){return change(e=>{if(e.pending)throw Error('Запрос ожидает подтверждения.');e.draft=null;e.error='';});},
  async submit(fields){await change(e=>{if(e.pending)return;if(!e.draft)throw Error('Нет открытой формы.');e.draft.fields=clone(fields);e.pending={action:e.draft.action,id:e.draft.id,input:{...clone(fields),operation_id:uuid()}};e.error='';e.result=null;e.status=null;});return this.retry();},
  async retry(){if(stopped||busy)return;busy=true;try{await chain;const pending=this.snapshot().pending;if(!pending)return;try{const result=await send(pending);if(!result||typeof result!=='object'||!('id' in result||'deleted' in result||'completion_id' in result||'atz_months' in result))throw Error('Неверный ответ');await change(e=>{e.pending=null;e.draft=null;e.result=result;e.error='';e.status=null;});}catch(error){await change(e=>{e.status=error.response?.status||null;const fields=Object.values(error.response?.data?.errors||{}).flat();e.error=e.status===422?fields.join('\n')||'Проверьте поля.':e.status===401?'Войдите заново через профиль. Запрос сохранён.':e.status===403?'Доступ запрещён. Запрос сохранён.':e.status===409?'Конфликт ключа операции. Запрос сохранён; обратитесь к администратору.':'Ответ не подтверждён. Повторите сохранённую отправку.';});}}finally{busy=false;}},
  unlock(){return change(e=>{if(e.status!==422)throw Error('Нельзя менять неподтверждённый запрос.');e.pending=null;e.error='';e.status=null;});},
  async stop(){stopped=true;await chain;}
 };
}
module.exports={createTrainingMutations};
