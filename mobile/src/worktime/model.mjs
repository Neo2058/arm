// Shared web/APK presentation and request state. No payroll formulas live here.
export const KINDS = { line: 'Линия', reserve: 'Резерв', surface: 'Резерв на поверхности' };
export const ABSENCES = { sick: 'Больничный', leave: 'Отпуск', study: 'Учёба' };
export const RATE_FIELDS = [
  ['line_rate_kopecks', 'Линия, ₽/ч'], ['reserve_rate_kopecks', 'Резерв, ₽/ч'],
  ['surface_rate_kopecks', 'На поверхности, ₽/ч'], ['evening_percent', 'Вечерняя доплата, %'], ['night_percent', 'Ночная доплата, %'],
];
export const WARNINGS = {
  absence_rest_context: 'Между дежурствами есть больничный, отпуск или учёба. Этот период не считается обычным междусменным отдыхом.',
  rest_calendar_incomplete: 'Не все дни отдыха подтверждены. Для части интервалов указан только минимальный ориентир.',
  legacy_rest_context: 'В расчёте соседних интервалов есть старые записи: норматив отдыха может быть неизвестен.',
  legacy_shifts_excluded: 'Старые записи не входят в новую денежную сводку. Они доступны в старом календаре веб-версии.',
  night_pair_incomplete: 'Ночная связка не завершена: проверьте соседние месяцы и коды смен.',
  night_pair_settings_conflict: 'В частях ночной связки различаются правила. Автоматический пересчёт не применён.',
};
export const PARTIAL = 'Это часть начислений: тариф, вечер и ночь. Классность, выслуга, премия, сверхурочные и удержания пока не включены. Итог не является зарплатой к выплате.';
export const minutes = value => value == null ? 'не определено' : `${Math.floor(value / 60)} ч ${String(value % 60).padStart(2, '0')} мин`;
export const money = value => `${((value || 0) / 100).toFixed(2).replace('.', ',')} ₽`;
export const localTime = value => (value || '').replace('T', ' ').slice(0, 16);
export function currentMonth() {
  return new Intl.DateTimeFormat('sv-SE', { timeZone: 'Europe/Moscow', year: 'numeric', month: '2-digit' }).format(new Date()).slice(0, 7);
}
export function monthDays(month) {
  const [year, m] = month.split('-').map(Number);
  return Array.from({ length: new Date(Date.UTC(year, m, 0)).getUTCDate() }, (_, i) => `${month}-${String(i + 1).padStart(2, '0')}`);
}
export function moveMonth(month, offset) {
  const [y, m] = month.split('-').map(Number), date = new Date(Date.UTC(y, m - 1 + offset, 1));
  return date.toISOString().slice(0, 7);
}
const clone = value => JSON.parse(JSON.stringify(value));
const fixed = value => (value / 100).toFixed(2);
function decimal(value, scale, max, label) {
  const text = String(value).trim().replace(',', '.');
  if (!(scale === 100 ? /^\d+(\.\d{1,2})?$/ : /^\d+$/).test(text)) throw new Error(`Проверьте поле «${label}».`);
  const [whole, fraction = ''] = text.split('.');
  const result = scale === 100 ? Number(whole) * 100 + Number(fraction.padEnd(2, '0')) : Number(whole);
  if (!Number.isSafeInteger(result) || result > max) throw new Error(`Превышено значение поля «${label}».`);
  return result;
}
function dateTime(value) {
  const text = String(value).trim().replace('T', ' ');
  if (!/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/.test(text)) throw new Error('Введите дату и время в формате ГГГГ-ММ-ДД ЧЧ:ММ.');
  return text;
}
export function shiftDraft(shift = null, date = `${currentMonth()}-01`) {
  if (shift?.type === 'deviation' && shift.absence_kind) return absenceDraft(shift);
  return { kind: 'shift', target: shift, values: {
    started_at: localTime(shift?.started_at) || `${date} 08:00`, ended_at: localTime(shift?.ended_at) || `${date} 16:00`,
    break_duration: String(shift?.break_duration || 0), shift_code: shift?.shift_code || '',
    start_location: shift?.start_location || '', end_location: shift?.end_location || '', refresh_snapshot: false,
    segments: (shift?.segments || []).map(s => ({ ...s, started_at: localTime(s.started_at), ended_at: localTime(s.ended_at) })),
  } };
}
export function absenceDraft(shift = null, date = `${currentMonth()}-01`) {
  return { kind: 'absence', target: shift, values: { absence_kind: shift?.absence_kind || 'sick', shift_date: shift?.shift_date || date, absence_end: shift?.absence_end || date } };
}
export function settingsDraft(data) {
  return { kind: 'settings', target: data, values: Object.fromEntries([
    ...RATE_FIELDS.map(([key]) => [key, key.endsWith('_kopecks') ? fixed(data.settings[key]) : String(data.settings[key])]),
    ['rest_dates', [...data.settings.rest_dates]],
  ]) };
}
export function requestFor(draft) {
  const v = draft.values;
  if (draft.kind === 'absence') {
    if (!ABSENCES[v.absence_kind] || !/^\d{4}-\d{2}-\d{2}$/.test(v.shift_date) || !/^\d{4}-\d{2}-\d{2}$/.test(v.absence_end)) throw new Error('Выберите вид периода и даты в формате ГГГГ-ММ-ДД.');
    const data = { type: 'deviation', absence_kind: v.absence_kind, shift_date: v.shift_date, absence_end: v.absence_end };
    if (draft.target?.id) data.expected_revision = draft.target.revision;
    return { method: draft.target?.id ? 'put' : 'post', url: `/shifts${draft.target?.id ? `/${draft.target.id}` : ''}`, month: v.shift_date.slice(0, 7), data };
  }
  if (draft.kind === 'settings') {
    const settings = {};
    for (const [key, label] of RATE_FIELDS) {
      const value = decimal(v[key], key.endsWith('_kopecks') ? 100 : 1, key.endsWith('_kopecks') ? 10000000 : 100, label);
      if (value !== draft.target.settings[key]) settings[key] = value;
    }
    const dates = [...new Set(v.rest_dates)].sort();
    if (JSON.stringify(dates) !== JSON.stringify([...draft.target.settings.rest_dates].sort())) settings.rest_dates = dates;
    if (!Object.keys(settings).length) throw new Error('Нет изменённых настроек.');
    return { method: 'patch', url: `/settings/${draft.target.month}`, month: draft.target.month, data: { expected_revision: draft.target.revision, settings } };
  }
  const started = dateTime(v.started_at), ended = dateTime(v.ended_at);
  const data = {
    shift_date: started.slice(0, 10), started_at: started, ended_at: ended,
    break_duration: decimal(v.break_duration, 1, 10080, 'Оплачиваемые перерывы'), shift_code: v.shift_code || null,
    start_location: v.start_location || null, end_location: v.end_location || null, route_id: draft.target?.route_id || null,
    segments: v.segments.length ? v.segments.map(s => ({ kind: s.kind, started_at: dateTime(s.started_at), ended_at: dateTime(s.ended_at) })) : null,
  };
  if (draft.target?.id) Object.assign(data, { expected_revision: draft.target.revision, refresh_snapshot: !!v.refresh_snapshot });
  return { method: draft.target?.id ? 'put' : 'post', url: `/shifts${draft.target?.id ? `/${draft.target.id}` : ''}`, month: data.shift_date.slice(0, 7), data };
}
export function errorText(error) {
  const status = error.response?.status;
  if (status === 401 || status === 419) return 'Войдите заново. Сохранённый запрос останется для повторной отправки.';
  if (status === 403) return 'Нет доступа. Проверьте активность аккаунта.';
  if (status === 404) return 'Запись недоступна. Обновите табель.';
  if (status === 409) return 'Данные изменились на другом устройстве. Загрузите текущую версию и сравните со своим вводом.';
  if (status === 422) return Object.values(error.response.data?.errors || {}).flat().join('\n') || 'Проверьте поля карточки.';
  if (status === 429) return 'Слишком много запросов. Подождите и повторите ту же отправку.';
  return 'Ответ сервера не подтверждён. Повторите ту же отправку; новый запрос создавать не нужно.';
}

export function createEditor({ storage, send, uuid }) {
  let state = { files: [], draft: null, pending: null, status: null, error: '', result: null, current: null };
  let chain = Promise.resolve(), busy = false, stopped = false;
  const listeners = new Set();
  const publish = () => listeners.forEach(fn => fn());
  const change = fn => {
    const next = chain.then(async () => {
      if (stopped) throw new Error('Редактор закрыт.');
      const copy = clone(state); fn(copy);
      await storage.write({ schema: 1, entries: { editor: copy } }); state = copy; publish();
    });
    chain = next.catch(() => {}); return next;
  };
  return {
    async load() { const saved = await storage.read(); if (saved) { if (!saved.entries?.editor || !Array.isArray(saved.entries.editor.files)) throw new Error('Не удалось прочитать черновик.'); state = saved.entries.editor; } publish(); },
    snapshot: () => clone(state),
    subscribe(fn) { listeners.add(fn); return () => listeners.delete(fn); },
    open(draft) { return change(s => { if (s.pending || s.draft) throw new Error('Сначала завершите открытую карточку.'); Object.assign(s, { draft, result: null, error: '', status: null, current: null }); }); },
    edit(values) { return change(s => { if (s.pending) throw new Error('Запрос уже отправлен.'); Object.assign(s.draft.values, values); s.result = null; }); },
    close() { return change(s => { if (s.pending) throw new Error('Сначала подтвердите результат отправки.'); s.draft = null; s.result = null; s.error = ''; }); },
    async submit() {
      await change(s => { if (!s.pending) { const request = requestFor(s.draft); request.data.operation_id = uuid(); s.pending = request; s.result = null; s.current = null; s.status = null; s.error = ''; } });
      return this.retry();
    },
    async retry() {
      if (busy || stopped) return;
      busy = true;
      try {
        await chain;
        if (stopped) return;
        const request = this.snapshot().pending;
        if (!request) return;
        try {
          const result = await send(request);
          if (request.url.startsWith('/shifts') ? !result?.shift?.id : !result?.month) throw new Error('Invalid response');
          await change(s => { s.pending = null; s.draft = null; s.result = result; s.error = ''; s.status = null; s.current = null; });
        } catch (error) {
          await change(s => { s.status = error.response?.status || null; s.error = errorText(error); });
        }
      } finally { busy = false; }
    },
    setCurrent(data) { return change(s => { if (s.status !== 409 || !s.pending) throw new Error('Нет конфликта.'); s.current = data; }); },
    unlock() { return change(s => {
      if (s.status === 409) {
        if (!s.current) throw new Error('Сначала загрузите текущую версию.');
        if (s.draft.kind === 'settings') {
          const oldPatch = s.pending.data.settings;
          const next = settingsDraft(s.current);
          for (const [key, value] of Object.entries(oldPatch)) next.values[key] = key.endsWith('_kopecks') ? fixed(value) : key === 'rest_dates' ? value : String(value);
          s.draft = next;
        } else s.draft.target = s.current.shift;
      } else if (s.status !== 422) throw new Error('Отправка ещё не подтверждена. Повторите тот же запрос.');
      s.pending = null; s.error = ''; s.status = null; s.current = null;
    }); },
    async stop() { stopped = true; await chain; },
  };
}
