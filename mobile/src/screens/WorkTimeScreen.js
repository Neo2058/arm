import React, { useEffect, useRef, useState } from 'react';
import { ActivityIndicator, Pressable, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';
import * as Crypto from 'expo-crypto';
import { API_URL, client } from '../api';
import { createUseWorkTime } from '../worktime/controller.mjs';
import { KINDS, RATE_FIELDS, WARNINGS, PARTIAL, localTime, minutes, money, monthDays, moveMonth } from '../worktime/model.mjs';
import { workTimeStorage } from '../worktime/storage';

const useWorkTime = createUseWorkTime(React);
const request = async op => (await client.request({ ...op, url: `/worktime${op.url}` })).data;
function Button({ children, onPress, disabled, selected }) {
  return <Pressable accessibilityRole="button" accessibilityState={{ disabled: !!disabled, selected: !!selected }} disabled={disabled} onPress={onPress} style={[s.button, selected && s.selected, disabled && s.disabled]}><Text style={s.buttonText}>{children}</Text></Pressable>;
}
function Field({ label, value, onChange, disabled, numeric, maxLength }) {
  return <View style={s.field}><Text style={s.meta}>{label}</Text><TextInput accessibilityLabel={label} style={s.input} value={value} editable={!disabled} onChangeText={onChange} autoCapitalize="none" autoCorrect={false} keyboardType={numeric ? 'decimal-pad' : 'default'} maxLength={maxLength} /></View>;
}
function Editor({ ctl }) {
  const { entry, busy } = ctl, draft = entry.draft;
  const [values, setValues] = useState(draft.values);
  const disabled = busy || !!entry.pending;
  const edit = patch => { setValues(v => ({ ...v, ...patch })); ctl.edit(patch); };
  const field = (key, label, numeric = false, maxLength) => <Field key={key} {...{ label, numeric, maxLength, disabled }} value={values[key]} onChange={value => edit({ [key]: value })} />;
  const partEdit = (index, patch) => edit({ segments: values.segments.map((part, i) => i === index ? { ...part, ...patch } : part) });
  return <View style={s.card}>
    <Text style={s.heading}>{draft.kind === 'settings' ? `Параметры · ${draft.target.month}` : draft.target?.id ? 'Изменение смены' : 'Новая смена'}</Text>
    {draft.kind === 'settings' ? <>
      <Text style={s.meta}>Ставки действуют с выбранного месяца до следующего изменения. Сохранённые смены сохраняют прежние ставки.</Text>
      {RATE_FIELDS.map(([key, label]) => field(key, label, true))}
      <Text style={s.heading}>Подтверждённые выходные</Text><Text style={s.meta}>Отметьте фактические выходные. Пустой день не считается выходным автоматически.</Text>
      <View style={s.days}>{monthDays(draft.target.month).map(date => <Pressable accessibilityRole="button" accessibilityLabel={`Выходной ${date}`} accessibilityState={{ selected: values.rest_dates.includes(date), disabled }} disabled={disabled} key={date} style={[s.day, values.rest_dates.includes(date) && s.selected]} onPress={() => edit({ rest_dates: values.rest_dates.includes(date) ? values.rest_dates.filter(d => d !== date) : [...values.rest_dates, date] })}><Text style={s.buttonText}>{Number(date.slice(-2))}</Text></Pressable>)}</View>
    </> : <>
      <Text style={s.meta}>Время московское: ГГГГ-ММ-ДД ЧЧ:ММ. После полуночи укажите следующую дату. Все перерывы оплачиваются.</Text>
      {field('started_at', 'Начало · дата и время')}{field('ended_at', 'Окончание · дата и время')}
      {field('break_duration', 'Оплачиваемые перерывы, минут', true)}{field('shift_code', 'Код смены (связка: 3+ / 4+ и 1)', false, 8)}
      {field('start_location', 'Место начала', false, 255)}{field('end_location', 'Место окончания', false, 255)}
      <Text style={s.heading}>Участки работы</Text><Text style={s.meta}>Без участков вся смена — линия. Участки должны покрывать всю смену подряд, без пробелов и пересечений.</Text>
      {values.segments.map((part, i) => <View key={i} style={s.part}>
        <Text style={s.text}>Участок {i + 1}</Text>
        {Object.entries(KINDS).map(([key, label]) => <Button key={key} disabled={disabled} selected={part.kind === key} onPress={() => partEdit(i, { kind: key })}>{label}</Button>)}
        <Field label="Начало участка" value={part.started_at} disabled={disabled} onChange={value => partEdit(i, { started_at: value })} />
        <Field label="Окончание участка" value={part.ended_at} disabled={disabled} onChange={value => partEdit(i, { ended_at: value })} />
        <Button disabled={disabled} onPress={() => edit({ segments: values.segments.filter((_, n) => n !== i) })}>Убрать участок</Button>
      </View>)}
      <Button disabled={disabled || values.segments.length >= 128} onPress={() => edit({ segments: [...values.segments, { kind: 'line', started_at: values.segments.at(-1)?.ended_at || values.started_at, ended_at: values.ended_at }] })}>+ Участок работы</Button>
      {!!draft.target?.id && <Button disabled={disabled} selected={values.refresh_snapshot} onPress={() => edit({ refresh_snapshot: !values.refresh_snapshot })}>{values.refresh_snapshot ? '✓' : '○'} Применить актуальные ставки и правила месяца</Button>}
      {!!draft.target?.id && <Text style={s.meta}>При переносе в другой месяц обновление ставок обязательно.</Text>}
      {!!ctl.preview && <View style={s.part}><Text style={s.text}>{minutes(ctl.preview.paid_minutes)} · {money(ctl.preview.subtotal_kopecks)}</Text><Text style={s.meta}>Вечер {minutes(ctl.preview.evening_minutes)} · ночь {minutes(ctl.preview.night_minutes)}. Ночная связка уточняется в месячной сводке после сохранения.</Text></View>}
    </>}
    {!!entry.current && <View style={s.part}><Text style={s.heading}>Текущая версия на сервере</Text>{draft.kind === 'settings' ? <>{RATE_FIELDS.map(([key, label]) => <Text key={key} style={s.text}>{label}: {key.endsWith('_kopecks') ? money(entry.current.settings[key]) : entry.current.settings[key]}</Text>)}<Text style={s.meta}>Выходные: {entry.current.settings.rest_dates.join(', ') || 'не указаны'}</Text></> : <>
      <Text style={s.text}>{localTime(entry.current.shift.started_at)} — {localTime(entry.current.shift.ended_at)}</Text>
      <Text style={s.text}>{minutes(entry.current.shift.calculation_result.paid_minutes)} · {money(entry.current.shift.calculation_result.subtotal_kopecks)}</Text>
      <Text style={s.meta}>Код {entry.current.shift.shift_code || '—'} · перерывы {entry.current.shift.break_duration} мин · {entry.current.shift.start_location || '—'} → {entry.current.shift.end_location || '—'}</Text>
      {(entry.current.shift.segments || []).map((part, i) => <Text key={i} style={s.meta}>{KINDS[part.kind]}: {localTime(part.started_at)} — {localTime(part.ended_at)}</Text>)}
    </>}<Text style={s.meta}>Ваш ввод остаётся в форме. Продолжение с новой версией применит ваши изменения после повторного сохранения.</Text></View>}
    {!entry.pending ? <>
      {draft.kind === 'shift' && <Button disabled={busy} onPress={() => ctl.previewShift(values)}>Проверить расчёт</Button>}
      <Button disabled={busy} onPress={() => ctl.submit(values)}>Сохранить на сервере</Button><Button disabled={busy} onPress={ctl.close}>Закрыть карточку</Button>
    </> : <>
      <Text style={s.meta}>Запрос сохранён на устройстве. До подтверждения его нельзя заменить новым.</Text>
      {entry.status === 422 ? <Button disabled={busy} onPress={ctl.unlock}>Исправить поля</Button> : entry.status === 409 && (draft.kind === 'settings' || draft.target?.id) ? <><Button disabled={busy} onPress={ctl.loadConflict}>Загрузить текущую версию</Button>{!!entry.current && <Button disabled={busy} onPress={ctl.unlock}>Продолжить с моим вводом и новой версией</Button>}</> : <Button disabled={busy} onPress={ctl.retry}>Повторить ту же отправку</Button>}
    </>}
  </View>;
}
export default function WorkTimeScreen({ userId }) {
  const ctl = useWorkTime({ scope: `${API_URL}:${userId}`, makeStorage: () => workTimeStorage(userId), request, uuid: Crypto.randomUUID });
  const scroll = useRef(null);
  useEffect(() => {
    if (ctl.entry?.draft || ctl.entry?.result) {
      const frame = requestAnimationFrame(() => scroll.current?.scrollTo({ y: 0, animated: true }));
      return () => cancelAnimationFrame(frame);
    }
  }, [ctl.entry?.draft?.kind, ctl.entry?.draft?.target?.id, ctl.entry?.result]);
  const data = ctl.summary, disabled = ctl.busy || !ctl.ready || !!ctl.entry?.draft;
  return <ScrollView ref={scroll} style={s.page} contentContainerStyle={s.content} keyboardShouldPersistTaps="handled">
    <Text style={s.title}>Личный табель</Text><Text style={s.note}>{PARTIAL}</Text>
    {!!ctl.error && <Text accessibilityRole="alert" style={s.error}>{ctl.error}</Text>}{!!ctl.entry?.error && <Text accessibilityRole="alert" style={s.error}>{ctl.entry.error}</Text>}
    {ctl.busy && <ActivityIndicator color="#fb923c" />}{!!ctl.entry?.result && <Text style={s.success}>Изменение подтверждено сервером. Сводка — за выбранный месяц.</Text>}
    {!!ctl.entry?.draft && <Editor key={`${ctl.entry.draft.kind}-${ctl.entry.draft.target?.id || ctl.entry.draft.target?.month || 'new'}-${ctl.entry.draft.target?.revision || 0}-${!!ctl.entry.pending}`} ctl={ctl} />}
    <View style={s.month}><Button disabled={ctl.busy} onPress={() => ctl.setMonth(moveMonth(ctl.month, -1))}>←</Button><Text style={s.heading}>{ctl.month}</Text><Button disabled={ctl.busy} onPress={() => ctl.setMonth(moveMonth(ctl.month, 1))}>→</Button></View>
    <Button disabled={ctl.busy} onPress={ctl.reload}>Обновить сводку</Button><Button disabled={disabled} onPress={() => ctl.openShift()}>+ Добавить смену</Button><Button disabled={disabled} onPress={ctl.openSettings}>Параметры месяца и выходные</Button>
    {!data ? <Text style={s.meta}>Сводка ещё не загружена.</Text> : <>
      <View style={s.card}><Text style={s.heading}>{minutes(data.totals.paid_minutes)}</Text><Text style={s.meta}>Оплаченное время · все перерывы включены</Text><Text style={s.text}>Вечер: {minutes(data.totals.evening_minutes)}</Text><Text style={s.text}>Ночь: {minutes(data.totals.night_minutes)}</Text><Text style={s.meta}>Фактические ночные: {minutes(data.totals.actual_night_minutes)}</Text><Text style={s.heading}>Часть начислений: {money(data.totals.subtotal_kopecks)}</Text>
        {Object.entries(data.by_kind_totals).map(([key, v]) => <View key={key} style={s.part}><Text style={s.text}>{KINDS[key]} · {minutes(v.paid_minutes)}</Text><Text style={s.meta}>Тариф {money(v.base_kopecks)} · вечер {money(v.evening_kopecks)} · ночь {money(v.night_kopecks)}</Text></View>)}
      </View>
      {data.warnings.map(code => <Text style={s.note} key={code}>{WARNINGS[code] || 'Требуется проверка данных месяца.'}</Text>)}
      {!!data.excluded_deviations_count && <Text style={s.note}>Отклонения старого календаря ({data.excluded_deviations_count}) в новый итог не включены.</Text>}
      <Text style={s.heading}>Календарь</Text><Text style={s.meta}>Нажмите день, чтобы добавить смену. В — подтверждённый выходной.</Text>
      <View style={s.days}>{monthDays(ctl.month).map(date => { const count = data.shifts.filter(shift => shift.shift_date === date).length, rest = ctl.settings?.settings.rest_dates.includes(date); return <Pressable key={date} accessibilityRole="button" accessibilityLabel={`Добавить смену ${date}`} disabled={disabled} style={[s.day, count > 0 && s.selected, disabled && s.disabled]} onPress={() => ctl.openDate(date)}><Text style={s.buttonText}>{Number(date.slice(-2))}</Text><Text style={s.calendarMark}>{count ? `${count} см.` : rest ? 'В' : '·'}</Text></Pressable>; })}</View>
      <Text style={s.heading}>Смены месяца</Text>{!data.shifts.length && <Text style={s.meta}>Новых записей пока нет.</Text>}
      {data.shifts.map(shift => <Pressable accessibilityRole="button" disabled={disabled} style={[s.card, disabled && s.disabled]} key={shift.id} onPress={() => ctl.openShift(shift.id)}><Text style={s.text}>{localTime(shift.started_at)} — {localTime(shift.ended_at)}</Text><Text style={s.heading}>{minutes(shift.result.paid_minutes)} · {money(shift.result.subtotal_kopecks)}</Text><Text style={s.meta}>Код {shift.shift_code || '—'} · вечер {minutes(shift.result.evening_minutes)} · ночь {minutes(shift.result.night_minutes)}</Text><Text style={s.meta}>Нажмите для изменения</Text></Pressable>)}
      <Text style={s.heading}>Отдых между дежурствами</Text>{!data.rest_intervals.length && <Text style={s.meta}>Для сравнения нужны соседние смены.</Text>}
      {data.rest_intervals.map((r, i) => <View style={s.card} key={i}><Text style={s.text}>{localTime(r.previous_ended_at) || 'Предыдущее дежурство'} → {localTime(r.next_started_at) || 'Следующее дежурство'}</Text><Text style={s.heading}>Отдых: {minutes(r.actual_minutes)}</Text><Text style={s.meta}>{r.required_minutes == null ? `Минимальный ориентир: ${minutes(r.minimum_required_minutes)}; календарь или предыдущая смена требуют уточнения.` : `Требуется: ${minutes(r.required_minutes)}.`}</Text><Text style={r.violation === true ? s.error : s.meta}>{r.violation === true ? 'Отдых короче нормы.' : r.violation === false ? 'Норма соблюдена.' : 'Окончательная оценка неизвестна.'}</Text></View>)}
    </>}
  </ScrollView>;
}
const s = StyleSheet.create({
  page: { flex: 1, backgroundColor: '#0b1018' }, content: { padding: 18, paddingBottom: 50, gap: 12 }, title: { color: '#fff', fontSize: 28, fontWeight: '700' }, heading: { color: '#fff', fontSize: 19, fontWeight: '700' },
  text: { color: '#e5edf8', fontSize: 15, lineHeight: 23 }, meta: { color: '#b0bfd2', fontSize: 14, lineHeight: 21 }, note: { color: '#fbd496', backgroundColor: '#33291b', padding: 14, borderRadius: 12, lineHeight: 21 },
  error: { color: '#fda4af', lineHeight: 23 }, success: { color: '#86efac' }, card: { backgroundColor: '#142031', borderWidth: 1, borderColor: '#38485f', borderRadius: 18, padding: 16, gap: 12 },
  part: { borderTopWidth: 1, borderTopColor: '#415069', paddingTop: 12, gap: 8 }, field: { gap: 5 }, input: { color: '#fff', borderWidth: 1, borderColor: '#61718b', borderRadius: 9, padding: 12, backgroundColor: '#0b1320', minHeight: 48 },
  button: { minHeight: 48, borderRadius: 10, padding: 12, backgroundColor: '#29384d', alignItems: 'center', justifyContent: 'center' }, buttonText: { color: '#fff', fontWeight: '600', textAlign: 'center' }, selected: { backgroundColor: '#9a3412', borderColor: '#fb923c', borderWidth: 1 }, disabled: { opacity: 0.5 },
  month: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' }, days: { flexDirection: 'row', flexWrap: 'wrap', gap: 6 }, calendarMark: { color: '#cbd5e1', fontSize: 10 }, day: { width: '12%', minHeight: 44, borderRadius: 8, backgroundColor: '#29384d', alignItems: 'center', justifyContent: 'center' },
});
