import { useEffect, useRef, useState } from 'react';
import { Alert, Pressable, Text, TextInput, View } from 'react-native';
import { useDiscipline } from '../discipline/context';
import s from '../discipline/styles';

export function useTaskEditor(refresh) {
  const { journal } = useDiscipline();
  const [entry, setEntry] = useState(() => journal.snapshot());
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const active = useRef(false);
  useEffect(() => { setEntry(journal.snapshot()); return journal.subscribe(() => setEntry(journal.snapshot())); }, [journal]);
  useEffect(() => { if (entry.result) refresh(); }, [entry.result]);
  const run = async operation => {
    if (active.current) return;
    active.current = true; setBusy(true); setError('');
    try { await operation(); } catch (e) { setError(e.message || 'Не удалось сохранить запрос на устройстве.'); }
    finally { active.current = false; setBusy(false); }
  };
  const act = (action, task) => {
    if (action === 'update') return run(() => journal.open(task));
    Alert.alert(action === 'delete' ? 'Удалить задачу?' : 'Отметить задачу выполненной?', task.title, [
      { text: 'Отмена', style: 'cancel' },
      { text: 'Подтвердить', style: action === 'delete' ? 'destructive' : 'default', onPress: () => run(() => journal.submit(action, task)) },
    ]);
  };
  return { journal, entry, busy, error, run, act };
}
function DraftForm({ draft, pending, busy, run, journal }) {
  const initial = useRef({ title: draft.title, description: draft.description, due_date: draft.due_date });
  const values = useRef({ ...initial.current });
  const [error, setError] = useState('');
  const edit = (field, value) => {
    values.current[field] = value;
    journal.edit({ [field]: value }).then(() => setError('')).catch(() => setError('Черновик пока не сохранён на устройстве. Повторите сохранение.'));
  };
  return <View style={s.card}>
    <Text style={s.heading}>{draft.task ? 'Изменение задачи' : 'Новая задача'}</Text>
    {!!error && <Text style={s.error}>{error}</Text>}
    {[['title', 'Название задачи', 255], ['description', 'Описание задачи', 20000], ['due_date', 'Срок: ГГГГ-ММ-ДД', 10]].map(([field, label, length]) => <TextInput key={field} accessibilityLabel={label} placeholder={label} placeholderTextColor="#9ca3af" style={s.input} defaultValue={initial.current[field]} maxLength={length} multiline={field === 'description'} editable={!pending && !busy} onChangeText={value => edit(field, value)} />)}
    {!pending && <><Pressable style={s.button} disabled={busy} onPress={() => run(async () => { await journal.edit(values.current); await journal.submit(draft.task ? 'update' : 'create'); })}><Text style={s.buttonText}>{busy ? 'Сохранение…' : 'Сохранить задачу'}</Text></Pressable><Pressable style={s.secondary} disabled={busy} onPress={() => run(() => journal.close())}><Text style={s.buttonText}>Закрыть форму</Text></Pressable></>}
  </View>;
}
export default function TaskEditor({ editor }) {
  const { journal, entry, busy, error, run } = editor;
  const draft = entry.draft, pending = entry.pending;
  return <View style={{ gap: 12 }}>
    {!!error && <Text style={s.error}>{error}</Text>}
    {!!entry.error && <Text style={s.error}>{entry.error}</Text>}
    {!!entry.result && <Text style={s.success}>Изменение сохранено на сервере.</Text>}
    {!draft && !pending && <Pressable style={s.button} disabled={busy} onPress={() => run(() => journal.open())}><Text style={s.buttonText}>Добавить личную задачу</Text></Pressable>}
    {!!draft && <DraftForm key={`${draft.task?.id || 'new'}-${draft.task?.version || 'new'}-${!!pending}`} {...{ draft, pending, busy, run, journal }} />}
    {!!pending && <><Text style={s.muted}>Изменение сохранено на устройстве и ожидает подтверждения. Повторная отправка не создаст дубликат.</Text>{[412, 422].includes(entry.status) ? <Pressable style={s.secondary} disabled={busy} onPress={() => run(() => journal.unlock())}><Text style={s.buttonText}>Проверить и исправить</Text></Pressable> : <Pressable style={s.button} disabled={busy} onPress={() => run(() => journal.retry())}><Text style={s.buttonText}>{busy ? 'Отправка…' : 'Повторить отправку задачи'}</Text></Pressable>}</>}
  </View>;
}
