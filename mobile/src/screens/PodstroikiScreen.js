import { useCallback, useEffect, useRef, useState } from 'react';
import { ActivityIndicator, Alert, Pressable, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';
import { useFocusEffect } from '@react-navigation/native';
import * as Crypto from 'expo-crypto';
import { client } from '../api';
import { IconLabel } from '../components/AppIcon';
import { canSubmitPodstroiki, createPodstroikiMutations } from '../podstroiki/mutations.cjs';
import { podstroikiStorage } from '../podstroiki/storage';

const SHIFTS = [
  { code: '1', label: '1 · 06:30–14:30' },
  { code: '2', label: '2 · 14:00–22:30' },
  { code: '3', label: '3 · 22:00–06:30' },
];

function addMonth(ym, delta) {
  const [year, month] = ym.split('-').map(Number);
  const date = new Date(Date.UTC(year, month - 1 + delta, 1));
  return `${date.getUTCFullYear()}-${String(date.getUTCMonth() + 1).padStart(2, '0')}`;
}

function currentMonth() {
  return new Date().toISOString().slice(0, 7);
}

function defaultDate(month) {
  const today = new Date().toISOString().slice(0, 10);
  if (today.slice(0, 7) === month && today >= `${month}-01`) return today;
  return `${month}-01`;
}

function Button({ children, onPress, disabled, selected }) {
  return (
    <Pressable
      accessibilityRole="button"
      accessibilityState={{ disabled: !!disabled, selected: !!selected }}
      disabled={disabled}
      onPress={onPress}
      style={[s.button, selected && s.selected, disabled && s.disabled]}
    >
      <Text style={s.buttonText}>{children}</Text>
    </Pressable>
  );
}

function Field({ label, value, onChange, disabled, maxLength }) {
  return (
    <View style={s.field}>
      <Text style={s.meta}>{label}</Text>
      <TextInput
        accessibilityLabel={label}
        style={s.input}
        value={value}
        editable={!disabled}
        onChangeText={onChange}
        autoCapitalize="none"
        autoCorrect={false}
        maxLength={maxLength}
      />
    </View>
  );
}

function Editor({ draft, pending, busy, patch, onSubmit, onClose }) {
  const [values, setValues] = useState(draft.fields);
  const locked = !!pending || busy;
  const change = (fields) => {
    setValues((current) => ({ ...current, ...fields }));
    patch(fields);
  };
  return (
    <View style={s.card}>
      <Text style={s.heading}>Новая заявка</Text>
      <Field label="Дата · ГГГГ-ММ-ДД" value={values.target_date} disabled={locked} maxLength={10} onChange={(value) => change({ target_date: value })} />
      <Field label="Маршрут" value={values.route_code} disabled={locked} maxLength={20} onChange={(value) => change({ route_code: value })} />
      <Text style={s.meta}>Смена</Text>
      {SHIFTS.map((shift) => (
        <Button key={shift.code} disabled={locked} selected={values.shift_code === shift.code} onPress={() => change({ shift_code: shift.code })}>{shift.label}</Button>
      ))}
      <View style={s.field}>
        <Text style={s.meta}>Что изменить</Text>
        <TextInput
          accessibilityLabel="Что изменить"
          style={[s.input, s.area]}
          value={values.details}
          editable={!locked}
          onChangeText={(value) => change({ details: value })}
          multiline
          maxLength={1000}
        />
      </View>
      {!pending && (
        <>
          <Button disabled={busy} onPress={() => onSubmit(values)}>Отправить заявку</Button>
          <Button disabled={busy} onPress={onClose}>Закрыть форму</Button>
        </>
      )}
    </View>
  );
}

export default function PodstroikiScreen({ user }) {
  const allowed = canSubmitPodstroiki(user);
  const queue = useRef(null);
  const [entry, setEntry] = useState({ draft: null, pending: null, result: null, error: '', status: null });
  const [ready, setReady] = useState(false);
  const [busy, setBusy] = useState(false);
  const [month, setMonth] = useState(() => addMonth(currentMonth(), 1));
  const [data, setData] = useState(null);
  const [loadError, setLoadError] = useState('');
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    let cancelled = false;
    (async () => {
      const storage = await podstroikiStorage(user.id);
      const mutations = createPodstroikiMutations({
        storage,
        uuid: () => Crypto.randomUUID(),
        send: async (pending) => {
          if (pending.action === 'cancel') {
            return (await client.delete(`/podstroiki/${encodeURIComponent(pending.id)}`)).data;
          }
          return (await client.post('/podstroiki', pending.input)).data;
        },
      });
      await mutations.load();
      if (cancelled) {
        await mutations.stop();
        return;
      }
      queue.current = mutations;
      setEntry(mutations.snapshot());
      mutations.subscribe(() => setEntry(mutations.snapshot()));
      setReady(true);
    })();
    return () => {
      cancelled = true;
      queue.current?.stop();
    };
  }, [user.id]);

  const load = useCallback(async () => {
    setLoadError('');
    setLoading(true);
    try {
      const { data: payload } = await client.get('/podstroiki', { params: { month } });
      setData(payload);
    } catch (error) {
      const code = error.response?.status;
      setData(null);
      setLoadError(code === 401 ? 'Войдите заново через профиль.' : 'Не удалось загрузить заявки. Проверьте соединение.');
    } finally {
      setLoading(false);
    }
  }, [month]);

  useFocusEffect(useCallback(() => {
    load();
  }, [load, entry.result]));

  const run = async (fn) => {
    if (busy) return;
    setBusy(true);
    try {
      await fn();
    } catch (error) {
      setLoadError(error.message || 'Не удалось сохранить запрос на устройстве.');
    } finally {
      setBusy(false);
    }
  };

  const patch = (fields) => {
    queue.current?.edit(fields).catch((error) => {
      setLoadError(error.message || 'Не удалось сохранить черновик.');
    });
  };

  const openForm = () => run(() => queue.current.open({
    target_date: defaultDate(month),
    route_code: '',
    shift_code: '1',
    details: '',
  }));

  const askCancel = (item) => Alert.alert('Отозвать заявку?', `Дата ${item.target_date}. Нарядчик перестанет видеть её как действующую.`, [
    { text: 'Оставить', style: 'cancel' },
    { text: 'Отозвать', style: 'destructive', onPress: () => run(() => queue.current.cancel(item.id)) },
  ]);

  const limit = data?.limit;
  const canOpen = allowed && ready && !entry.draft && !entry.pending && (limit?.unlimited || limit?.can_submit);
  const months = [currentMonth(), addMonth(currentMonth(), 1), addMonth(currentMonth(), 2)];

  return (
    <ScrollView style={s.page} contentContainerStyle={s.content} keyboardShouldPersistTaps="handled">
      <Text style={s.title}>Подстройки смен</Text>
      <Text style={s.note}>Заявка уходит нарядчику. Смена в сетке не меняется сама. Это не занятия АТЗ.</Text>
      {!allowed && <Text style={s.error}>Подавать заявки могут машинисты и инструкторы.</Text>}
      <View style={s.month}>
        {months.map((value) => (
          <Button key={value} selected={month === value} onPress={() => setMonth(value)}>{value}</Button>
        ))}
      </View>
      <Button disabled={loading} onPress={load}>Обновить список</Button>
      {loading && <ActivityIndicator color="#fb923c" />}
      {!!loadError && <Text accessibilityRole="alert" style={s.error}>{loadError}</Text>}
      {!!entry.error && <Text accessibilityRole="alert" style={s.error}>{entry.error}</Text>}
      {!!entry.result && <Text style={s.success}>{entry.result.message || 'Заявка сохранена на сервере.'}</Text>}
      {limit && (
        <View style={s.card}>
          <Text style={s.heading}>{limit.month_label}</Text>
          {limit.unlimited
            ? <Text style={s.meta}>Инструктору лимит по количеству не применяется. Уже подано: {limit.used}.</Text>
            : <Text style={s.meta}>Лимит машиниста: осталось {limit.remaining} из {limit.max_approved}. Подано: {limit.used}.</Text>}
          {!!data?.tab_number && <Text style={s.meta}>Табельный {data.tab_number}</Text>}
        </View>
      )}
      {entry.draft && (
        <Editor
          key={entry.pending ? 'pending' : 'draft'}
          draft={entry.draft}
          pending={entry.pending}
          busy={busy}
          patch={patch}
          onSubmit={(values) => run(() => queue.current.submit(values))}
          onClose={() => run(() => queue.current.close())}
        />
      )}
      {entry.pending && (
        <>
          <Text style={s.note}>Точный запрос сохранён на устройстве. Повтор не создаст другую заявку.</Text>
          <Button disabled={busy} onPress={() => run(() => queue.current.retry())}>Повторить отправку</Button>
          {entry.status === 422 && <Button disabled={busy} onPress={() => run(() => queue.current.unlock())}>Исправить поля</Button>}
        </>
      )}
      {canOpen && <Button disabled={busy} onPress={openForm}>+ Новая подстройка</Button>}
      {limit && !limit.unlimited && !limit.can_submit && !entry.pending && (
        <Text style={s.meta}>Лимит на этот месяц исчерпан. Нарядчик задаёт его в сетке.</Text>
      )}
      <Text style={s.heading}>Ваши заявки</Text>
      {!(data?.podstroikas || []).length && !loading && <Text style={s.meta}>Заявок за выбранный месяц нет.</Text>}
      {(data?.podstroikas || []).map((item) => (
        <View key={item.id} style={s.card}>
          <Text style={s.text}>{item.target_date} · маршрут {item.route_code || '—'} · смена {item.shift_code || '—'}</Text>
          <Text style={item.is_applied ? s.success : s.meta}>{item.status_label}</Text>
          {!!item.details && <Text style={s.meta}>{item.details}</Text>}
          {!!item.warning && <Text style={s.note}>{item.warning}</Text>}
          {item.can_cancel && !entry.pending && allowed && (
            <Button disabled={busy} onPress={() => askCancel(item)}>Отозвать заявку</Button>
          )}
        </View>
      ))}
      {!ready && <Text style={s.meta}>Открываются сохранённые отправки…</Text>}
    </ScrollView>
  );
}

export function PodstroikiEntry({ onPress }) {
  return (
    <Pressable accessibilityRole="button" accessibilityLabel="Подстройки смен" style={s.card} onPress={onPress}>
      <IconLabel name="send" textStyle={s.heading}>Подстройки смен</IconLabel>
      <Text style={s.meta}>Отправить заявку нарядчику на дату, маршрут и смену</Text>
    </Pressable>
  );
}

const s = StyleSheet.create({
  page: { flex: 1, backgroundColor: '#0b1018' },
  content: { padding: 18, paddingBottom: 50, gap: 12 },
  title: { color: '#fff', fontSize: 28, fontWeight: '700' },
  heading: { color: '#fff', fontSize: 19, fontWeight: '700' },
  text: { color: '#e5edf8', fontSize: 15, lineHeight: 23 },
  meta: { color: '#b0bfd2', fontSize: 14, lineHeight: 21 },
  note: { color: '#fbd496', backgroundColor: '#33291b', padding: 14, borderRadius: 12, lineHeight: 21 },
  error: { color: '#fda4af', lineHeight: 23 },
  success: { color: '#86efac' },
  card: { backgroundColor: '#142031', borderWidth: 1, borderColor: '#38485f', borderRadius: 18, padding: 16, gap: 12 },
  field: { gap: 5 },
  input: { color: '#fff', borderWidth: 1, borderColor: '#61718b', borderRadius: 9, padding: 12, backgroundColor: '#0b1320', minHeight: 48 },
  area: { minHeight: 96, textAlignVertical: 'top' },
  button: { minHeight: 48, borderRadius: 10, padding: 12, backgroundColor: '#29384d', alignItems: 'center', justifyContent: 'center' },
  buttonText: { color: '#fff', fontWeight: '600', textAlign: 'center' },
  selected: { backgroundColor: '#9a3412', borderColor: '#fb923c', borderWidth: 1 },
  disabled: { opacity: 0.5 },
  month: { flexDirection: 'row', gap: 8 },
});
