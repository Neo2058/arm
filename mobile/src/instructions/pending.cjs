function createPending({ storage, send }) {
  let state = { schema: 1, entries: { editor: { files: [], pending: null, error: '', status: null } } };
  let chain = Promise.resolve(), busy = false, stopped = false;
  const listeners = new Set();
  const emit = () => listeners.forEach(fn => fn());
  const change = fn => { const job = chain.then(async () => {
    if (stopped) return;
    storage.assertActive(); const next = JSON.parse(JSON.stringify(state)); fn(next.entries.editor);
    await storage.write(next); if (!stopped) { state = next; emit(); }
  }); chain = job.catch(() => {}); return job; };
  return {
    snapshot: () => JSON.parse(JSON.stringify(state.entries.editor)),
    subscribe(fn) { listeners.add(fn); return () => listeners.delete(fn); },
    async load() { const saved = await storage.read(); storage.assertActive(); if (!stopped && saved) state = saved; emit(); },
    async confirm(document) {
      await change(e => { if (e.pending && e.pending.id !== document.id) throw Error('Завершите сохранённое подтверждение.');
        e.pending ??= { id: document.id, title: document.title, body: { confirmed: true }, files: [] }; e.error = ''; });
      return this.retry();
    },
    async retry() {
      if (busy || stopped) return false;
      busy = true;
      try {
        await chain; storage.assertActive(); const p = this.snapshot().pending;
        if (!p) return false;
        try {
          const result = await send(p);
          if (result?.signed !== true || Number(result.document_id) !== Number(p.id) || !result.signed_at) throw Error('Некорректный ответ');
          await change(e => { e.pending = null; e.error = ''; e.status = null; }); return !stopped;
        } catch (error) {
          await change(e => { e.status = error.response?.status || null;
            e.error = e.status === 401 ? 'Войдите заново через профиль. Подтверждение сохранено.' :
              [403, 404].includes(e.status) ? 'Документ или доступ изменились. Обновите список.' :
              e.status === 422 ? 'Подтверждение отклонено. Обновите список и проверьте сведения.' :
              'Нет подтверждения сервера. Повторите тот же запрос; дата первой росписи сохранится.';
          }); return false;
        }
      } finally { busy = false; }
    },
    dismissRejected() { return change(e => { if (![403,404,422].includes(e.status)) throw Error('Сначала получите ответ сервера.'); e.pending = null; e.status = null; e.error = ''; }); },
    async stop() { stopped = true; listeners.clear(); await chain; },
  };
}
module.exports = { createPending };
