function createMutations({ storage, send, uuid }) {
  let state = { schema: 1, entries: { editor: { files: [], draft: null, pending: null, result: null, error: '', current: null, status: null } } };
  let chain = Promise.resolve(), busy = false, stopped = false;
  const listeners = new Set();
  const publish = () => { for (const fn of listeners) fn(); };
  const change = fn => { const p = chain.then(async () => { if (stopped) return; const next = JSON.parse(JSON.stringify(state)); fn(next.entries.editor); await storage.write(next); state = next; publish(); }); chain = p.catch(() => {}); return p; };
  return {
    async load() { const saved = await storage.read(); if (saved) state = saved; publish(); },
    snapshot() { return JSON.parse(JSON.stringify(state.entries.editor)); },
    subscribe(fn) { listeners.add(fn); return () => listeners.delete(fn); },
    open(task = null) { return change(e => { if (e.pending) throw new Error('Сначала завершите предыдущую отправку.'); e.draft = { task, title: task?.title || '', description: task?.description || '', due_date: task?.due_date || '' }; e.error = ''; e.result = null; }); },
    edit(patch) { return change(e => { if (e.pending) throw new Error('Запрос зафиксирован.'); Object.assign(e.draft, patch); }); },
    close() { return change(e => { if (e.pending) throw new Error('Отправка ещё не подтверждена.'); e.draft = null; }); },
    async submit(action, task) {
      await change(e => {
        if (e.pending) return;
        const input = { operation_id: uuid() };
        const target = task || e.draft?.task;
        if (action !== 'create') { if (!target?.version) throw new Error('Обновите задачу перед отправкой.'); input.version = String(target.version); }
        if (['create', 'update'].includes(action)) {
          if (!e.draft?.title.trim()) throw new Error('Введите название задачи.');
          Object.assign(input, { title: e.draft.title.trim(), description: e.draft.description || null, due_date: e.draft.due_date || null });
        }
        e.pending = { action, id: target ? String(target.id) : null, input, files: [] }; e.result = null; e.error = ''; e.status = null;
      });
      return this.retry();
    },
    async retry() {
      if (busy || stopped) return;
      busy = true;
      try {
        await chain;
        const pending = this.snapshot().pending;
        if (!pending) return;
        try {
          const result = await send(pending);
          if (!result || (!result.task && !result.deleted)) throw new Error('Invalid response');
          await change(e => { e.pending = null; e.draft = null; e.result = result; e.error = ''; e.status = null; });
        } catch (error) {
          await change(e => { e.status = error.response?.status || null; e.current = error.response?.data?.current || null;
            const fields = Object.values(error.response?.data?.errors || {}).flat();
            e.error = e.status === 412 ? 'Задача изменена на другом устройстве. Проверьте актуальную версию.' : e.status === 401 ? 'Войдите заново через профиль. Запрос сохранён.' : e.status === 403 ? 'Нет доступа к изменению журнала.' : e.status === 422 ? fields.join('\n') || 'Проверьте поля и назначение колонны.' : e.status === 404 ? 'Задача недоступна или уже удалена.' : 'Ответ не подтверждён. Повторите ту же отправку; дубликат не создастся.';
          });
        }
      } finally { busy = false; }
    },
    unlock() { return change(e => { if (![412, 422].includes(e.status)) throw new Error('Нужно подтвердить результат предыдущей отправки.'); if (e.current && e.draft) e.draft.task = e.current; e.pending = null; e.error = ''; e.status = null; }); },
    async stop() { stopped = true; await chain; },
  };
}
module.exports = { createMutations };
