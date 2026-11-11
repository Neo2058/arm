import { useCallback, useEffect, useRef, useState } from 'react';
import { ActivityIndicator, Pressable, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';
import { useFocusEffect } from '@react-navigation/native';
import * as Crypto from 'expo-crypto';
import { client } from '../api';
import { formularStorage } from '../formular/storage';
import { createFormularMutations } from '../formular/mutations.cjs';

const labels = { not_submitted: 'Не внесено', pending: 'На проверке', confirmed: 'Подтверждено', returned: 'Возвращено' };
function Button({ children, onPress, disabled }) { return <Pressable accessibilityRole="button" disabled={disabled} onPress={onPress} style={[s.button, disabled && { opacity: 0.4 }]}><Text style={s.text}>{children}</Text></Pressable>; }
function DraftInput({ draft, disabled, onChange }) {
  // FileSystem acknowledgements rerender the editor; keep native defaultValue stable.
  const initial = useRef(draft.text);
  return <TextInput defaultValue={initial.current} multiline maxLength={draft.action === 'submit' ? 10000 : 2000} editable={!disabled} style={s.input} accessibilityLabel="Текст записи или замечание" onChangeText={onChange} />;
}
export default function FormularScreen({ user }) {
  const [review, setReview] = useState(false), [data, setData] = useState(null), [error, setError] = useState('');
  const [filters, setFilters] = useState({}), [page, setPage] = useState(1), [editor, setEditor] = useState(null), [busy, setBusy] = useState(false);
  const [selector, setSelector] = useState(null), [search, setSearch] = useState('');
  const scroll = useRef(null);
  const manager = useRef(null), alive = useRef(false), input = useRef(Promise.resolve());
  const refreshId = useRef(0);
  const load = useCallback(async () => {
    const id = ++refreshId.current;
    try { const { data: result } = await client.get(review ? '/formular/review' : '/formular', { params: review ? { ...filters, page } : {} });
      if (alive.current && id === refreshId.current) { setData(result); setError(''); }
    } catch (e) { if (alive.current && id === refreshId.current) { setData(null); setError(e.response?.status === 401 ? 'Войдите заново через профиль.' : e.response?.status === 422 ? 'Укажите колонну через администратора.' : 'Не удалось загрузить формуляр. Повторите запрос.'); } }
  }, [review, filters, page]);
  useEffect(() => {
    alive.current = true; let unsubscribe, own;
    (async () => {
      const storage = await formularStorage(user.id);
      if (!alive.current) return;
      own = createFormularMutations({ storage, uuid: () => Crypto.randomUUID(), send: async p => (await client.post(p.path, p.body)).data });
      manager.current = own; unsubscribe = own.subscribe(() => { if (alive.current) setEditor(own.snapshot()); }); await own.load();
      if (alive.current) setEditor(own.snapshot());
    })().catch(() => { if (alive.current) setError('Не удалось открыть сохранённый черновик. Данные не удалены.'); });
    return () => { alive.current = false; refreshId.current++; unsubscribe?.(); own?.stop(); manager.current = null; };
  }, [user.id]);
  useFocusEffect(useCallback(() => { load(); return () => { refreshId.current++; }; }, [load]));
  const act = async fn => { if (busy) return; setBusy(true); try { await input.current; await fn(); } catch (e) { if (alive.current) setError(e.message || 'Не удалось сохранить данные.'); } finally { if (alive.current) setBusy(false); } };
  const open = target => act(async () => { await manager.current?.open(target); setTimeout(() => scroll.current?.scrollToEnd({ animated: true }), 100); });
  const filter = patch => { setPage(1); setData(null); setFilters(f => ({ ...f, ...patch })); };
  const finish = async retry => { const ok = await (retry ? manager.current.retry() : manager.current.submit()); if (ok) await load(); };
  return <ScrollView ref={scroll} style={s.screen} keyboardShouldPersistTaps="handled" contentContainerStyle={{ padding: 16, gap: 12 }}>
    <Text style={s.title}>Формуляр</Text><Text style={s.meta}>Запись по заданию передаётся инструктору на проверку.</Text>
    {user.role === 'instructor' && <View style={s.row}><Button onPress={() => { setData(null); setReview(false); }}>Мои записи</Button><Button onPress={() => { setData(null); setReview(true); }}>Проверка колонны</Button></View>}
    <Button onPress={load}>Обновить</Button>
    {editor?.draft && <Button onPress={() => scroll.current?.scrollToEnd({ animated: true })}>Открыть сохранённый черновик</Button>}
    {!!error && <Text accessibilityRole="alert" style={s.error}>{error}</Text>}
    {!data && !error && <ActivityIndicator color="#fb923c" />}
    {review && data && <>
      <Text style={s.text}>Колонна {data.column} · Всего {data.stats.total}</Text>
      <Text style={s.meta}>{Object.entries(labels).map(([k, label]) => `${label}: ${data.stats[k]}`).join(' · ')}</Text>
      <View style={s.row}><Button onPress={() => filter({ status: undefined })}>Все</Button>{Object.entries(labels).map(([key, label]) => <Button key={key} onPress={() => filter({ status: key })}>{filters.status === key ? '✓ ' : ''}{label}</Button>)}</View>
      <Button onPress={() => { setSelector('crew'); setSearch(''); }}>Машинист: {data.crew.find(m => m.id === filters.user_id)?.name || 'Все'}</Button>
      <Button onPress={() => { setSelector('tasks'); setSearch(''); }}>Задание: {data.tasks.find(t => t.id === filters.task_id)?.title || 'Все'}</Button>
      {selector && <View style={s.card}>
        <TextInput value={search} onChangeText={setSearch} placeholder="Поиск" placeholderTextColor="#a7b3c5" accessibilityLabel="Поиск по фильтру" style={s.input} />
        <Button onPress={() => { filter({ [selector === 'crew' ? 'user_id' : 'task_id']: undefined }); setSelector(null); }}>Все</Button>
        <ScrollView nestedScrollEnabled style={{ maxHeight: 260 }}>{data[selector].filter(item => (item.name || item.title).toLowerCase().includes(search.toLowerCase())).map(item => <Button key={item.id} onPress={() => { filter({ [selector === 'crew' ? 'user_id' : 'task_id']: item.id }); setSelector(null); }}>{item.name || item.title}</Button>)}</ScrollView>
        <Button onPress={() => setSelector(null)}>Закрыть выбор</Button>
      </View>}
      {data.records.map(r => <View key={`${r.user_id}-${r.task_id}`} style={s.card}>
        <Text style={s.title}>{r.name}</Text><Text style={s.text}>{r.title}</Text><Text style={s.meta}>{labels[r.status || 'not_submitted']} {r.completed_at || ''}</Text>
        {!!r.entry_text && <Text selectable style={s.text}>{r.entry_text}</Text>}
        {!!r.review_comment && <Text style={s.warning}>Замечание: {r.review_comment}</Text>}
        {r.status === 'pending' && <View style={s.row}><Button disabled={!editor || busy || !!editor?.draft} onPress={() => open({ action: 'confirmed', id: r.id, version: r.version, title: `${r.name} · ${r.title}`, text: '' })}>Подтвердить</Button><Button disabled={!editor || busy || !!editor?.draft} onPress={() => open({ action: 'returned', id: r.id, version: r.version, title: `${r.name} · ${r.title}`, text: '' })}>Вернуть с замечанием</Button></View>}
      </View>)}
      {!data.records.length && <Text style={s.meta}>По выбранным условиям записей нет.</Text>}
      <View style={s.row}>{page > 1 && <Button onPress={() => setPage(p => p - 1)}>Назад</Button>}{data.pagination.next_page && <Button onPress={() => setPage(data.pagination.next_page)}>Далее</Button>}</View>
    </>}
    {!review && data?.tasks.map(t => <View key={t.id} style={s.card}>
      <Text style={s.title}>{t.title}</Text><Text style={s.meta}>{t.description}</Text><Text style={s.text}>{labels[t.log?.status || 'not_submitted']}</Text>
      {!!t.log?.entry_text && <Text selectable style={s.text}>{t.log.entry_text}</Text>}
      {!!t.log?.review_comment && <Text style={s.warning}>Замечание: {t.log.review_comment}</Text>}
      {(!t.log || t.log.status === 'returned') && <Button disabled={!editor || busy || !!editor?.draft} onPress={() => open({ action: 'submit', id: t.id, version: t.log?.version || 0, title: t.title, text: t.log?.entry_text || '' })}>Внести запись</Button>}
    </View>)}
    {!review && data?.tasks.length === 0 && <Text style={s.meta}>Активных заданий пока нет.</Text>}
    {editor?.draft && <View style={s.card}>
      <Text style={s.title}>{editor.draft.title}</Text><Text style={s.meta}>{editor.draft.action === 'submit' ? 'Текст записи' : editor.draft.action === 'returned' ? 'Замечание для исправления' : 'Подтверждение проверки'}</Text>
      <DraftInput key={`${editor.draft.action}-${editor.draft.id}`} draft={editor.draft} disabled={busy || !!editor.pending} onChange={value => { const job = manager.current.edit(value); input.current = job; job.catch(() => { if (alive.current) setError('Не удалось сохранить текст на устройстве.'); }); }} />
      {!!editor.error && <Text style={s.error}>{editor.error}</Text>}
      <Button disabled={busy} onPress={() => act(() => finish(!!editor.pending))}>{busy ? 'Сохраняем…' : editor.pending ? 'Повторить тот же запрос' : editor.draft.action === 'submit' ? 'Отправить на проверку' : editor.draft.action === 'returned' ? 'Вернуть с замечанием' : 'Подтвердить запись'}</Button>
      {!editor.pending && <Button disabled={busy} onPress={() => act(() => manager.current.close())}>Закрыть черновик</Button>}
      {editor.pending && [409, 422, 403, 404].includes(editor.status) && <Button disabled={busy} onPress={() => act(async () => { await manager.current.correct(); await load(); })}>Снять отклонённый запрос · текст сохранить</Button>}
      {editor.status === 409 && <Text style={s.warning}>После обновления закройте этот черновик и откройте актуальную запись. Текст можно скопировать перед закрытием.</Text>}
    </View>}
  </ScrollView>;
}
const s = StyleSheet.create({ screen: { flex: 1, backgroundColor: '#0b1018' }, row: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 }, card: { padding: 16, gap: 10, borderRadius: 16, backgroundColor: '#141e2e' }, title: { color: '#fff', fontSize: 18, fontWeight: '600' }, text: { color: '#fff', fontSize: 15 }, meta: { color: '#a7b3c5', fontSize: 14 }, error: { color: '#fca5a5' }, warning: { color: '#fcd34d' }, button: { padding: 12, borderWidth: 1, borderColor: '#52637b', borderRadius: 12 }, input: { minHeight: 100, padding: 12, color: '#fff', borderWidth: 1, borderColor: '#718096', borderRadius: 10, textAlignVertical: 'top' } });
