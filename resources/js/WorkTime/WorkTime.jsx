import React, { useState } from 'react';
import axios from 'axios';
import { CalendarDays, Clock3, ChevronLeft, ChevronRight, Plus, Settings2 } from 'lucide-react';
import LegacyCalendar from '../WorkCalendar/WorkCalendar';
import { createUseWorkTime } from '../../../mobile/src/worktime/controller.mjs';
import { ABSENCES, KINDS, RATE_FIELDS, WARNINGS, PARTIAL, localTime, minutes, money, monthDays, moveMonth } from '../../../mobile/src/worktime/model.mjs';
import { browserStorage } from './storage';
import './worktime.css';

const useWorkTime = createUseWorkTime(React);
const request = async op => (await axios.request({ ...op, url: `/api/worktime/v2${op.url}`, headers: { Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' }, timeout: 25000 })).data;
const heading = month => new Intl.DateTimeFormat('ru-RU', { month: 'long', year: 'numeric', timeZone: 'UTC' }).format(new Date(`${month}-01T12:00:00Z`));
function Result({ result }) {
    return <div className="wt-result" role="status"><strong>{minutes(result.paid_minutes)}</strong><span>Вечер: {minutes(result.evening_minutes)} · Ночь: {minutes(result.night_minutes)}</span><span>Часть начислений: {money(result.subtotal_kopecks)}</span><small>Предпросмотр одной смены. Ночная связка уточняется в месячной сводке после сохранения.</small></div>;
}
function Editor({ ctl }) {
    const { entry, busy } = ctl, draft = entry.draft;
    const [values, setValues] = useState(draft.values);
    const disabled = busy || !!entry.pending;
    const edit = patch => { setValues(v => ({ ...v, ...patch })); ctl.edit(patch); };
    const field = (key, label, type = 'text', extra = {}) => <label>{label}<input {...extra} type={type} disabled={disabled} value={values[key]} onChange={e => edit({ [key]: e.target.value })} /></label>;
    const segment = (index, patch) => edit({ segments: values.segments.map((s, i) => i === index ? { ...s, ...patch } : s) });
    return <section className="wt-card" aria-labelledby="wt-editor-title">
        <h2 id="wt-editor-title">{draft.kind === 'settings' ? `Параметры · ${heading(draft.target.month)}` : draft.kind === 'absence' ? 'Больничный, отпуск или учёба' : draft.target?.id ? 'Редактирование смены' : 'Новая смена'}</h2>
        {draft.kind === 'absence' ? <>
            <p>Даты начала и окончания включаются целиком (московское время), технический предел — 366 дней. Период не входит в отработанные часы. Оплата пока не рассчитана.</p>
            <label>Вид периода<select disabled={disabled} value={values.absence_kind} onChange={e => edit({ absence_kind: e.target.value })}>{Object.entries(ABSENCES).map(([key,label]) => <option key={key} value={key}>{label}</option>)}</select></label>
            <div className="wt-fields">{field('shift_date', 'Начало периода', 'date')}{field('absence_end', 'Окончание периода включительно', 'date')}</div>
        </> : draft.kind === 'settings' ? <>
            <p>Ставки действуют с выбранного месяца до следующего изменения. Сохранённые смены сохраняют свои ставки.</p>
            <div className="wt-fields">{RATE_FIELDS.map(([key, label]) => <React.Fragment key={key}>{field(key, label, 'text', { inputMode: 'decimal' })}</React.Fragment>)}</div>
            <h3>Подтверждённые выходные</h3><p>Отметьте только фактические выходные. День без записи смены сам по себе выходным не считается.</p>
            <div className="wt-days">{monthDays(draft.target.month).map(date => <button type="button" disabled={disabled} key={date} aria-label={`Выходной ${date}`} aria-pressed={values.rest_dates.includes(date)} onClick={() => edit({ rest_dates: values.rest_dates.includes(date) ? values.rest_dates.filter(d => d !== date) : [...values.rest_dates, date] })}>{Number(date.slice(-2))}</button>)}</div>
        </> : <>
            <p>Время московское. Для окончания после полуночи укажите следующую дату. Все перерывы оплачиваются.</p>
            <div className="wt-fields">
                {field('started_at', 'Начало · ГГГГ-ММ-ДД ЧЧ:ММ')}{field('ended_at', 'Окончание · ГГГГ-ММ-ДД ЧЧ:ММ')}
                {field('break_duration', 'Оплачиваемые перерывы, минут', 'number', { min: 0, step: 1 })}
                {field('shift_code', 'Код смены (для связки: 3+ / 4+ и 1)', 'text', { maxLength: 8 })}
                {field('start_location', 'Место начала', 'text', { maxLength: 255 })}{field('end_location', 'Место окончания', 'text', { maxLength: 255 })}
            </div>
            <h3>Участки работы</h3><p>Без участков вся смена считается линией. Если добавляете участки, покройте всю смену подряд, без пробелов и пересечений.</p>
            {values.segments.map((part, i) => <fieldset key={i} disabled={disabled}><legend>Участок {i + 1}</legend>
                <label>Вид работы<select value={part.kind} onChange={e => segment(i, { kind: e.target.value })}>{Object.entries(KINDS).map(([key, label]) => <option key={key} value={key}>{label}</option>)}</select></label>
                <div className="wt-fields"><label>Начало<input value={part.started_at} onChange={e => segment(i, { started_at: e.target.value })} /></label><label>Окончание<input value={part.ended_at} onChange={e => segment(i, { ended_at: e.target.value })} /></label></div>
                <button type="button" onClick={() => edit({ segments: values.segments.filter((_, n) => n !== i) })}>Убрать участок</button>
            </fieldset>)}
            <button type="button" disabled={disabled || values.segments.length >= 128} onClick={() => edit({ segments: [...values.segments, { kind: 'line', started_at: values.segments.at(-1)?.ended_at || values.started_at, ended_at: values.ended_at }] })}>+ Участок работы</button>
            {!!draft.target?.id && <label className="wt-check"><input type="checkbox" disabled={disabled} checked={values.refresh_snapshot} onChange={e => edit({ refresh_snapshot: e.target.checked })} />Применить актуальные ставки и правила месяца к этой смене (обязательно при переносе в другой месяц)</label>}
            {!!ctl.preview && <Result result={ctl.preview} />}
        </>}
        {!!entry.current && <div className="wt-result"><strong>Текущая версия на сервере</strong>{draft.kind === 'settings' ? RATE_FIELDS.map(([key, label]) => <span key={key}>{label}: {key.endsWith('_kopecks') ? money(entry.current.settings[key]) : entry.current.settings[key]}</span>) : draft.kind === 'absence' ? <span>{ABSENCES[entry.current.shift.absence_kind]}: {entry.current.shift.shift_date} — {entry.current.shift.absence_end}, оплата не рассчитана</span> : <><span>{localTime(entry.current.shift.started_at)} — {localTime(entry.current.shift.ended_at)}</span><span>{minutes(entry.current.shift.calculation_result.paid_minutes)} · {money(entry.current.shift.calculation_result.subtotal_kopecks)}</span></>}{draft.kind === 'settings' ? <span>Выходные: {entry.current.settings.rest_dates.join(', ') || 'не указаны'}</span> : <><span>Код {entry.current.shift.shift_code || '—'} · перерывы {entry.current.shift.break_duration} мин · {entry.current.shift.start_location || '—'} → {entry.current.shift.end_location || '—'}</span>{(entry.current.shift.segments || []).map((part, i) => <span key={i}>{KINDS[part.kind]}: {localTime(part.started_at)} — {localTime(part.ended_at)}</span>)}</>}<small>Ваш ввод остаётся в форме. Продолжение с новой версией применит ваши изменения после повторного сохранения.</small></div>}
        <div className="wt-actions">
            {!entry.pending ? <>
                {draft.kind === 'shift' && <button disabled={busy} onClick={() => ctl.previewShift(values)}>Проверить расчёт</button>}
                <button className="wt-primary" disabled={busy} onClick={() => ctl.submit(values)}>Сохранить на сервере</button>
                <button disabled={busy} onClick={ctl.close}>Закрыть карточку</button>
            </> : <>
                <p>Запрос сохранён в этой вкладке. До подтверждения его нельзя заменить новым.</p>
                {entry.status === 422 ? <button disabled={busy} onClick={ctl.unlock}>Исправить поля</button> : entry.status === 409 && (draft.kind === 'settings' || draft.target?.id) ? <>
                    <button disabled={busy} onClick={ctl.loadConflict}>Загрузить текущую версию</button>
                    {!!entry.current && <button disabled={busy} onClick={ctl.unlock}>Продолжить с моим вводом и новой версией</button>}
                </> : <button disabled={busy} onClick={ctl.retry}>Повторить ту же отправку</button>}
            </>}
        </div>
    </section>;
}
function Tabular({ ctl }) {
    const s = ctl.summary, disabled = ctl.busy || !ctl.ready || !!ctl.entry?.draft;
    const days = monthDays(ctl.month), first = (new Date(`${ctl.month}-01T12:00:00Z`).getUTCDay() + 6) % 7;
    return <>
        <div className="wt-actions"><button aria-label="Предыдущий месяц" disabled={ctl.busy} onClick={() => ctl.setMonth(moveMonth(ctl.month, -1))}><ChevronLeft size={18} /></button><h2>{heading(ctl.month)}</h2><button aria-label="Следующий месяц" disabled={ctl.busy} onClick={() => ctl.setMonth(moveMonth(ctl.month, 1))}><ChevronRight size={18} /></button><button disabled={ctl.busy} onClick={ctl.reload}>Обновить</button></div>
        <div className="wt-actions"><button className="wt-primary" disabled={disabled} onClick={() => ctl.openShift()}><Plus size={18} />Добавить смену</button><button disabled={disabled} onClick={ctl.openAbsence}>Больничный / отпуск / учёба</button><button disabled={disabled} onClick={ctl.openSettings}><Settings2 size={18} />Параметры месяца</button></div>
        {!s ? <p role="status">Сводка ещё не загружена.</p> : <>
            <div className="wt-stats">
                <div><Clock3 /><strong>{minutes(s.totals.paid_minutes)}</strong><span>Оплачено по времени · перерывы включены</span></div>
                <div><strong>{minutes(s.totals.evening_minutes)}</strong><span>Вечерние часы</span></div><div><strong>{minutes(s.totals.night_minutes)}</strong><span>Ночные часы · фактически {minutes(s.totals.actual_night_minutes)}</span></div>
                <div><strong>{money(s.totals.subtotal_kopecks)}</strong><span>Часть начислений</span></div>
            </div>
            <section className="wt-card"><h3>Начисления по видам работы</h3>{Object.entries(s.by_kind_totals).map(([key, v]) => <p key={key}><strong>{KINDS[key]}: {minutes(v.paid_minutes)}</strong><br />Тариф {money(v.base_kopecks)} · вечер {money(v.evening_kopecks)} · ночь {money(v.night_kopecks)}</p>)}</section>
            {s.warnings.map(code => <p className="wt-note" key={code}>{WARNINGS[code] || 'Требуется проверка данных месяца.'}</p>)}
            {!!s.excluded_deviations_count && <p className="wt-note">Отклонения старого календаря ({s.excluded_deviations_count}) в новый итог не включены.</p>}
            <div className="wt-calendar">{['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'].map(d => <span key={d}>{d}</span>)}{Array.from({ length: first }, (_, i) => <span key={`gap${i}`} />)}{days.map(date => {
                const shifts = s.shifts.filter(v => v.shift_date === date), absence = (s.absences || []).find(a => a.shift_date <= date && a.absence_end >= date), rest = !absence && ctl.settings?.settings.rest_dates.includes(date);
                return <div className={`wt-day${rest ? ' wt-rest' : ''}`} key={date}><button disabled={disabled} aria-label={`Добавить смену ${date}`} onClick={() => ctl.openDate(date)}>{Number(date.slice(-2))}{rest ? ' · В' : ''}</button>{!!absence && <button className="wt-shift" disabled={disabled} onClick={() => ctl.openShift(absence.id)}>{absence.label}</button>}{shifts.map(shift => <button className="wt-shift" key={shift.id} disabled={disabled} onClick={() => ctl.openShift(shift.id)}>{localTime(shift.started_at).slice(11)}–{localTime(shift.ended_at).slice(11)}<small>{minutes(shift.result.paid_minutes)}</small></button>)}</div>;
            })}</div>
            <section className="wt-card"><h3>Больничные, отпуск и учёба</h3>{!(s.absences || []).length && <p>Периодов в этом месяце нет.</p>}{(s.absences || []).map(a => <button className="wt-shift-row" disabled={disabled} key={a.id} onClick={() => ctl.openShift(a.id)}><span>{a.label}: {a.shift_date} — {a.absence_end} включительно</span><strong>{a.days_in_month} дн. в месяце · оплата не рассчитана</strong></button>)}</section>
            <section className="wt-card"><h3>Смены месяца</h3>{!s.shifts.length && <p>Новых записей за этот месяц пока нет.</p>}{s.shifts.map(shift => <button className="wt-shift-row" disabled={disabled} key={shift.id} onClick={() => ctl.openShift(shift.id)}><span>{localTime(shift.started_at)} — {localTime(shift.ended_at)}{shift.shift_code ? ` · ${shift.shift_code}` : ''}</span><strong>{minutes(shift.result.paid_minutes)} · {money(shift.result.subtotal_kopecks)}</strong></button>)}</section>
            <section className="wt-card"><h3>Отдых между дежурствами</h3>{!s.rest_intervals.length && <p>Для сравнения нужны соседние смены.</p>}{s.rest_intervals.map((r, i) => <p className={r.violation === true ? 'wt-error' : ''} key={i}>{localTime(r.previous_ended_at) || 'Предыдущее дежурство'} → {localTime(r.next_started_at) || 'Следующее дежурство'}: {minutes(r.actual_minutes)}<br />{r.absence_context ? 'Внутри периода есть больничный, отпуск или учёба. Норма междусменного отдыха здесь не оценивается.' : r.required_minutes == null ? `Минимальный ориентир: ${minutes(r.minimum_required_minutes)}; календарь или предыдущая смена требуют уточнения.` : `Требуется: ${minutes(r.required_minutes)}.`} {r.violation === true ? 'Отдых короче нормы.' : r.violation === false ? 'Норма соблюдена.' : 'Окончательная оценка неизвестна.'}</p>)}</section>
        </>}
    </>;
}
function PersonalCalendar({ userId }) {
    const scope = `${location.origin}:${userId}`;
    const ctl = useWorkTime({ scope, makeStorage: () => browserStorage(scope), request, uuid: () => crypto.randomUUID() });
    return <div className="wt"><header><CalendarDays /><h1>Личный табель</h1><p>Смены, оплачиваемое время и отдых · Москва</p></header><p className="wt-note">{PARTIAL}</p>
        {!!ctl.error && <p role="alert" className="wt-error">{ctl.error}</p>}{!!ctl.entry?.error && <p role="alert" className="wt-error">{ctl.entry.error}</p>}
        {ctl.busy && <p role="status">Выполняется запрос…</p>}{!!ctl.entry?.result && <p role="status" className="wt-success">Изменение подтверждено сервером. Сводка показана для выбранного месяца.</p>}
        {!!ctl.entry?.draft && <Editor key={`${ctl.entry.draft.kind}-${ctl.entry.draft.target?.id || ctl.entry.draft.target?.month || 'new'}-${ctl.entry.draft.target?.revision || 0}-${!!ctl.entry.pending}`} ctl={ctl} />}
        <Tabular ctl={ctl} />
    </div>;
}
export default function WorkTime({ userId }) {
    const [legacy, setLegacy] = useState(false);
    // Keep both mounted: switching history must not interrupt a persisted request.
    return <><div className="wt wt-actions"><button aria-pressed={!legacy} onClick={() => setLegacy(false)}>Личный табель</button><button aria-pressed={legacy} onClick={() => setLegacy(true)}>Старый календарь</button></div><div hidden={legacy}><PersonalCalendar userId={userId} /></div>{legacy && <LegacyCalendar />}</>;
}
