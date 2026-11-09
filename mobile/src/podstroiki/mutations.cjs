function canSubmitPodstroiki(user) {
  return user?.role === 'driver' || user?.role === 'instructor';
}

function normalizeSubmit(fields) {
  const target_date = String(fields?.target_date || '').trim();
  const route_code = String(fields?.route_code || '').trim();
  const shift_code = String(fields?.shift_code || '').trim();
  const details = String(fields?.details || '').trim();
  if (!/^\d{4}-(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/.test(target_date)) {
    throw new Error('Укажите дату в формате ГГГГ-ММ-ДД.');
  }
  if (!route_code) throw new Error('Укажите номер маршрута.');
  if (!['1', '2', '3'].includes(shift_code)) throw new Error('Выберите смену 1, 2 или 3.');
  if (!details) throw new Error('Опишите, что нужно подстроить.');
  return { target_date, route_code, shift_code, details };
}

function errorMessage(error) {
  const status = error.response?.status || null;
  if (status === 422) {
    const fields = Object.values(error.response?.data?.errors || {}).flat();
    return error.response?.data?.message || fields.join('\n') || 'Проверьте поля.';
  }
  if (status === 401) return 'Войдите заново через профиль. Запрос сохранён.';
  if (status === 403) return 'Нарядчики не подают заявки за себя. Запрос сохранён.';
  if (status === 409) return 'Ключ операции уже использован. Запрос сохранён; обратитесь к администратору.';
  return 'Ответ не подтверждён. Повторите сохранённую отправку.';
}

function accepted(pending, result) {
  if (!result || typeof result !== 'object' || result.success !== true) return false;
  if (pending.action === 'submit') return Boolean(result.podstroika?.id);
  if (pending.action === 'cancel') return true;
  return false;
}

function createPodstroikiMutations({ storage, send, uuid }) {
  let state = { schema: 1, entries: { editor: { files: [], draft: null, pending: null, result: null, error: '', status: null } } };
  let chain = Promise.resolve();
  let busy = false;
  let stopped = false;
  const listeners = new Set();
  const clone = (value) => JSON.parse(JSON.stringify(value));
  const change = (fn) => {
    const p = chain.then(async () => {
      if (stopped) return;
      const next = clone(state);
      fn(next.entries.editor);
      await storage.write(next);
      state = next;
      listeners.forEach((listener) => listener());
    });
    chain = p.catch(() => {});
    return p;
  };
  return {
    async load() {
      const saved = await storage.read();
      if (saved) state = saved;
      listeners.forEach((listener) => listener());
    },
    snapshot() { return clone(state.entries.editor); },
    subscribe(fn) { listeners.add(fn); return () => listeners.delete(fn); },
    open(fields) {
      return change((e) => {
        if (e.pending) throw new Error('Сначала завершите сохранённую отправку.');
        e.draft = { action: 'submit', fields: { target_date: '', route_code: '', shift_code: '1', details: '', ...clone(fields || {}) } };
        e.result = null;
        e.error = '';
      });
    },
    edit(fields) {
      return change((e) => {
        if (e.pending) throw new Error('Запрос ожидает подтверждения.');
        if (!e.draft || e.draft.action !== 'submit') throw new Error('Нет открытой формы.');
        Object.assign(e.draft.fields, clone(fields));
      });
    },
    close() {
      return change((e) => {
        if (e.pending) throw new Error('Запрос ожидает подтверждения.');
        e.draft = null;
        e.error = '';
      });
    },
    async submit(fields) {
      await change((e) => {
        if (e.pending) return;
        const input = { ...normalizeSubmit(fields || e.draft?.fields || {}), operation_id: uuid() };
        e.draft = { action: 'submit', fields: { target_date: input.target_date, route_code: input.route_code, shift_code: input.shift_code, details: input.details } };
        e.pending = { action: 'submit', id: null, input };
        e.error = '';
        e.result = null;
        e.status = null;
      });
      return this.retry();
    },
    async cancel(id) {
      await change((e) => {
        if (e.pending) return;
        if (!id) throw new Error('Нет заявки для отзыва.');
        e.pending = { action: 'cancel', id: String(id), input: {} };
        e.error = '';
        e.result = null;
        e.status = null;
      });
      return this.retry();
    },
    async retry() {
      if (stopped || busy) return;
      busy = true;
      try {
        await chain;
        const pending = this.snapshot().pending;
        if (!pending) return;
        try {
          const result = await send(pending);
          if (!accepted(pending, result)) throw new Error('Неверный ответ');
          await change((e) => {
            e.pending = null;
            e.draft = null;
            e.result = result;
            e.error = '';
            e.status = null;
          });
        } catch (error) {
          await change((e) => {
            e.status = error.response?.status || null;
            e.error = errorMessage(error);
          });
        }
      } finally {
        busy = false;
      }
    },
    unlock() {
      return change((e) => {
        if (e.status !== 422) throw new Error('Нельзя менять неподтверждённый запрос.');
        e.pending = null;
        e.error = '';
        e.status = null;
      });
    },
    async stop() {
      stopped = true;
      await chain;
    },
  };
}

module.exports = { canSubmitPodstroiki, createPodstroikiMutations, normalizeSubmit };
