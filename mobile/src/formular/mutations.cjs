// Persist exact request before transmission. An uncertain response may only retry that request.
function createFormularMutations({ storage, send, uuid }) {
  let state = { schema: 1, entries: { editor: { files: [], draft: null, pending: null, error: '', status: null } } };
  let chain = Promise.resolve(), busy = false, stopped = false;
  const listeners = new Set();
  const emit = () => listeners.forEach(fn => fn());
  const change = fn => {
    const job = chain.then(async () => {
      if (stopped) return;
      storage.assertActive();
      const next = JSON.parse(JSON.stringify(state)); fn(next.entries.editor);
      await storage.write(next); if (!stopped) { state = next; emit(); }
    }); chain = job.catch(() => {}); return job;
  };
  return {
    async load() { const saved = await storage.read(); storage.assertActive(); if (!stopped && saved) state = saved; emit(); },
    snapshot: () => JSON.parse(JSON.stringify(state.entries.editor)),
    subscribe(fn) { listeners.add(fn); return () => listeners.delete(fn); },
    open(target) { return change(e => { if (e.pending) throw new Error('Сначала завершите сохранённую отправку.'); e.draft = { ...target }; e.error = ''; }); },
    edit(value) { return change(e => { if (e.pending) throw new Error('Запрос уже отправлен.'); e.draft.text = value; }); },
    close() { return change(e => { if (e.pending) throw new Error('Сначала завершите отправку.'); e.draft = null; }); },
    async submit() {
      await change(e => {
        if (e.pending) return;
        if (!e.draft) throw new Error('Откройте запись.');
        const d = e.draft;
        if ((d.action === 'submit' || d.action === 'returned') && !d.text?.trim()) throw new Error(d.action === 'submit' ? 'Введите текст записи.' : 'Укажите замечание.');
        e.pending = { path: d.action === 'submit' ? `/formular/tasks/${d.id}` : `/formular/logs/${d.id}/review`,
          body: { operation_id: uuid(), version: d.version, ...(d.action === 'submit' ? { entry_text: d.text } : { decision: d.action, review_comment: d.text || null }) }, files: [] };
        e.error = ''; e.status = null;
      });
      return this.retry();
    },
    async retry() {
      if (busy || stopped) return false;
      busy = true;
      try {
        await chain; storage.assertActive(); const pending = this.snapshot().pending;
        if (!pending) return false;
        try {
          const result = await send(pending);
          if (!result?.log?.id) throw new Error('Некорректный ответ сервера.');
          await change(e => { e.pending = null; e.draft = null; e.error = ''; e.status = null; });
          return !stopped;
        } catch (error) {
          await change(e => { e.status = error.response?.status || null;
            e.error = e.status === 409 ? 'Запись изменилась. Обновите список и сравните данные. Сохранённый текст доступен ниже.' :
              e.status === 401 ? 'Войдите заново через профиль. Запрос сохранён.' :
              e.status === 403 || e.status === 404 ? 'Доступ к записи изменился. Запрос сохранён.' :
              e.status === 422 ? 'Проверьте поля: ' + Object.values(error.response?.data?.errors || {}).flat().join(' ') :
              'Нет подтверждения сервера. Повторите тот же запрос, чтобы не создать дубль.';
          }); return false;
        }
      } finally { busy = false; }
    },
    // Only definitive rejection permits correction. Network uncertainty cannot discard the UUID.
    correct() { return change(e => { if (![409, 422, 403, 404].includes(e.status)) throw new Error('Сначала получите подтверждение сервера.'); e.pending = null; e.error = ''; e.status = null; }); },
    async stop() { stopped = true; listeners.clear(); await chain; },
  };
}
module.exports = { createFormularMutations };
