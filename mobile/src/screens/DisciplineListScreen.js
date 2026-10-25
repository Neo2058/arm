import { useCallback, useRef, useState } from 'react';
import { ActivityIndicator, AppState, FlatList, Pressable, RefreshControl, Text, View } from 'react-native';
import { useFocusEffect } from '@react-navigation/native';
import { listAssignments } from '../discipline/api';
import { LABELS, errorMessage } from '../discipline/contract.cjs';
import s from '../discipline/styles';
import { useDiscipline } from '../discipline/context';

export default function DisciplineListScreen({ navigation }) {
  const { state } = useDiscipline();
  const [offline, setOffline] = useState(Object.keys(state.entries).length > 0);
  const [items, setItems] = useState([]);
  const [status, setStatus] = useState('');
  const [loading, setLoading] = useState(Object.keys(state.entries).length === 0);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [unread, setUnread] = useState(0);
  const [nextPage, setNextPage] = useState(null);
  const active = useRef(null);

  useFocusEffect(useCallback(() => {
    const controller = new AbortController();
    active.current = { controller, busy: false };
    const refresh = async () => {
      if (active.current?.controller !== controller || active.current.busy) return;
      active.current.busy = true;
      setBusy(true);
      try {
        const data = await listAssignments(1, status, controller.signal);
        if (controller.signal.aborted) return;
        setItems(data.assignments); setNextPage(data.pagination.next_page); setUnread(data.unread_count); setError(''); setOffline(false);
      } catch (e) { if (!controller.signal.aborted) { setError(errorMessage(e)); setOffline(true); } }
      finally {
        if (!controller.signal.aborted) { active.current.busy = false; setBusy(false); setLoading(false); }
      }
    };
    active.current.refresh = refresh;
    refresh();
    const timer = setInterval(() => { if (AppState.currentState === 'active') refresh(); }, 30000);
    const sub = AppState.addEventListener('change', state => { if (state === 'active') refresh(); });
    return () => { controller.abort(); clearInterval(timer); sub.remove(); };
  }, [status]));

  const more = async () => {
    const request = active.current;
    if (!request || request.busy || !nextPage) return;
    request.busy = true; setBusy(true);
    try {
      const data = await listAssignments(nextPage, status, request.controller.signal);
      if (request.controller.signal.aborted) return;
      setItems(old => [...old, ...data.assignments.filter(item => !old.some(x => x.id === item.id))]);
      setNextPage(data.pagination.next_page); setUnread(data.unread_count); setError(''); setOffline(false);
    } catch (e) { if (!request.controller.signal.aborted) { setError(errorMessage(e)); setOffline(true); } }
    finally { if (!request.controller.signal.aborted) { request.busy = false; setBusy(false); } }
  };
  const saved = Object.values(state.entries).filter(e => e.info?.assignment).map(e => ({ ...e.info.assignment, localStatus: e.status }));
  const visible = offline ? saved.filter(a => !status || a.status === status) : items;
  if (loading) return <View style={s.center}><ActivityIndicator color="#fb923c" /></View>;
  return <FlatList style={s.page} contentContainerStyle={s.content} data={visible} keyExtractor={item => item.id}
    refreshControl={<RefreshControl tintColor="#fb923c" refreshing={busy} onRefresh={() => active.current?.refresh()} />}
    ListHeaderComponent={<View style={{ gap: 12 }}>
      <Text style={s.title}>Поручения руководителя</Text>
      <Pressable style={s.secondary} onPress={() => navigation.navigate('Journal')}><Text style={s.buttonText}>Журнал инструктора</Text></Pressable>
      <Text style={s.muted}>Непрочитанных событий: {unread}. Обновление каждые 30 секунд при открытом разделе.</Text>
      {offline && <Text style={s.muted}>Нет свежих данных. Показаны сохранённые на устройстве карточки.</Text>}
      <Text style={s.muted}>В очереди: {Object.values(state.entries).filter(e => e.pending).length}. Черновиков: {Object.values(state.entries).filter(e => !e.pending && (e.response || e.files.length)).length}.</Text>
      <View style={s.row}>{[['', 'Все'], ...Object.entries(LABELS)].map(([key, label]) => <Pressable key={key} accessibilityRole="button" accessibilityState={{ selected: status === key }} onPress={() => setStatus(key)} style={[s.chip, status === key && s.selected]}><Text style={s.body}>{label}</Text></Pressable>)}</View>
      {!!error && <Text accessibilityRole="alert" style={s.error}>{error}</Text>}
      {!!error && <Pressable style={s.secondary} onPress={() => active.current?.refresh()}><Text style={s.buttonText}>Повторить загрузку</Text></Pressable>}
    </View>}
    ListEmptyComponent={<Text style={s.muted}>{error ? 'Данные не обновлены.' : 'Поручений в этом разделе пока нет.'}</Text>}
    renderItem={({ item }) => <Pressable style={s.card} onPress={() => navigation.navigate('DisciplineDetail', { id: item.id })} accessibilityRole="button">
      <Text style={s.heading}>{item.unread ? '● ' : ''}{item.title}</Text>
      <Text style={s.accent}>{item.status_label}</Text>
      <Text style={s.muted}>Срок: {item.due_date.split('-').reverse().join('.')} · {item.author.name}</Text>
      {item.is_overdue && <Text style={s.error}>Просрочено</Text>}
    </Pressable>}
    ListFooterComponent={!offline && nextPage ? <Pressable style={[s.secondary, busy && s.disabled]} disabled={busy} onPress={more}><Text style={s.buttonText}>Загрузить ещё</Text></Pressable> : null}
  />;
}
