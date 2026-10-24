const { freezeSubmission, validateDraft, validateFiles, errorMessage } = require('./contract.cjs');
const copy = value => JSON.parse(JSON.stringify(value));

// Storage is injected: production uses the app-private filesystem, tests simulate crashes.
function createQueue({ storage, send, uuid, now = Date.now }) {
  let state = { schema: 1, entries: {} }, chain = Promise.resolve(), stopped = false, running = false;
  const listeners = new Set();
  const emit = () => { for (const fn of listeners) fn(); };
  const serial = fn => { const next = chain.then(fn); chain = next.catch(() => {}); return next; };
  const commit = async next => { await storage.write(next); state = next; emit(); };
  const mutate = fn => serial(async () => { if (stopped) throw new Error('Queue stopped'); const next = copy(state); const result = await fn(next); await commit(next); return result; });
  return {
    async load() { const saved = await storage.read(); if (saved) { if (saved.schema !== 1 || !saved.entries) throw new Error('Unsupported local storage'); state = saved; } emit(); },
    subscribe(fn) { listeners.add(fn); return () => listeners.delete(fn); },
    snapshot() { return copy(state); },
    cache(info) { return mutate(next => { const id = String(info.assignment.id); const old = next.entries[id] || { response: '', files: [], pending: null, status: 'draft' }; const a = String(old.info?.assignment.version || '0'), b = String(info.assignment.version); if (a.length > b.length || (a.length === b.length && a > b)) return; next.entries[id] = { ...old, info, updatedAt: now() }; }); },
    draft(id, response, files) { return mutate(next => { const e = next.entries[id]; if (!e || e.pending) throw new Error('Отправка уже зафиксирована.'); e.response = response; e.files = files; e.status = 'draft'; e.error = ''; e.updatedAt = now(); }); },
    edit(id, response) { return mutate(next => { const e = next.entries[id]; if (!e || e.pending) throw new Error('Отправка уже зафиксирована.'); e.response = response; e.status = 'draft'; }); },
    addFiles(id, files) { return mutate(async next => { const e = next.entries[id]; if (!e || e.pending) throw new Error('Отправка уже зафиксирована.'); const message = validateFiles([...e.files, ...files], e.info.limits); if (message) throw new Error(message); e.files.push(...await storage.importFiles(files)); }); },
    removeFile(id, fileId) { return mutate(next => { const e = next.entries[id]; if (!e || e.pending) throw new Error('Отправка уже зафиксирована.'); e.files = e.files.filter(f => f.id !== fileId); }); },
    enqueue(id) { return mutate(next => {
      const e = next.entries[id]; if (!e) throw new Error('Сначала загрузите карточку.');
      if (e.pending) { e.nextAttemptAt = 0; return; }
      const message = validateDraft(e.info.assignment, e.response, e.files, e.info.limits);
      if (message) throw new Error(message);
      e.pending = freezeSubmission(e.info.assignment, e.response, e.files, uuid());
      e.status = 'queued'; e.error = ''; e.attempts = 0; e.nextAttemptAt = 0;
    }); },
    // Only a definitive server refusal permits editing a pending operation.
    unlock(id) { return mutate(next => { const e = next.entries[id]; if (e?.status !== 'blocked' || ![409, 412, 413, 422].includes(e.httpStatus)) throw new Error('Сначала нужно получить определённый ответ сервера.'); e.pending = null; e.status = 'draft'; e.error = ''; }); },
    async flush() {
      if (stopped || running) return;
      running = true;
      try {
        for (const [id, e] of Object.entries(state.entries)) {
          if (stopped) break;
          if (!e.pending || e.status !== 'queued' || (e.nextAttemptAt || 0) > now()) continue;
          const snapshot = copy(e.pending);
          try {
            await storage.verify(snapshot.files);
            const result = await send(snapshot);
            if (stopped) break;
            await mutate(next => { const current = next.entries[id]; if (current.pending?.operation_id !== snapshot.operation_id) return; Object.assign(current, { info: result, response: '', files: [], pending: null, status: 'sent', error: '', httpStatus: null }); });
          } catch (error) {
            if (stopped) break;
            const status = error?.response?.status;
            await mutate(next => {
              const current = next.entries[id]; if (current.pending?.operation_id !== snapshot.operation_id) return;
              const blocked = error.code === 'LOCAL_FILE_MISSING' || [401, 403, 404, 409, 412, 413, 422].includes(status);
              current.status = blocked ? 'blocked' : 'queued'; current.httpStatus = status || null;
              current.error = error.code === 'LOCAL_FILE_MISSING' ? 'Сохранённый файл недоступен. Отправка приостановлена; содержимое запроса не изменено.' : errorMessage(error);
              current.attempts = (current.attempts || 0) + 1;
              current.nextAttemptAt = now() + Math.min(300000, 15000 * 2 ** Math.min(current.attempts - 1, 5));
            });
          }
        }
      } finally { running = false; }
    },
    retry(id) { return mutate(next => { const e = next.entries[id]; if (e?.pending && ![409, 412, 413, 422].includes(e.httpStatus)) { e.status = 'queued'; e.nextAttemptAt = 0; } }); },
    async stop() { stopped = true; await chain; },
  };
}
module.exports = { createQueue };
