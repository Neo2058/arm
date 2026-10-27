import { useCallback, useRef, useState } from 'react';
import { ActivityIndicator, Alert, AppState, Linking, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { useFocusEffect } from '@react-navigation/native';
import Constants from 'expo-constants';
import { client } from '../api';
import ProfileEditor from './ProfileEditor';
import AppIcon, { IconLabel } from '../components/AppIcon';

export default function ProfileScreen({ user, onLogout, onReauthenticate, onProfileSaved, onOpenWorkTime }) {
  const [telegram, setTelegram] = useState(null);
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  const active = useRef(null);
  const pendingLink = useRef(null);
  const actionBusy = useRef(false);

  useFocusEffect(useCallback(() => {
    setBusy(false);
    const controller = new AbortController();
    const refresh = async () => {
      try {
        const { data } = await client.get('/account/telegram', { signal: controller.signal });
        if (!controller.signal.aborted) {
          setTelegram(data); setError('');
          if (data.linked) pendingLink.current = null;
        }
      } catch (e) {
        if (!controller.signal.aborted) setError(e.response?.status === 401 ? 'Войдите заново для управления Telegram.' : 'Не удалось проверить Telegram. Проверьте соединение и повторите.');
      }
    };
    active.current = { refresh, controller };
    refresh();
    const sub = AppState.addEventListener('change', state => { if (state === 'active') refresh(); });
    return () => { controller.abort(); sub.remove(); active.current = null; };
  }, [user.id]));

  const link = async () => {
    if (actionBusy.current) return;
    actionBusy.current = true; setBusy(true); setError('');
    const request = active.current;
    try {
      if (!pendingLink.current || pendingLink.current.expires <= Date.now()) {
        const { data } = await client.post('/account/telegram', {}, { signal: request?.controller.signal });
        // Never persist or log the one-time link. Reuse on browser-open failure.
        if (request?.controller.signal.aborted) return;
        if (!/^https:\/\/t\.me\/[A-Za-z0-9_]+\?start=[A-Za-z0-9]{48}$/.test(data.url || '')) throw new Error('Invalid link');
        pendingLink.current = { url: data.url, expires: Date.now() + 9 * 60 * 1000 };
      }
      await Linking.openURL(pendingLink.current.url);
    } catch (e) {
      if (request?.controller.signal.aborted) return;
      setError(e.response?.status === 409 ? 'Telegram уже привязан. Обновите статус.' : e.response?.status === 429 ? 'Слишком много запросов. Повторите позже.' : 'Не удалось открыть привязку. Проверьте Telegram и интернет, затем повторите.');
    } finally {
      actionBusy.current = false;
      if (!request?.controller.signal.aborted) setBusy(false);
    }
  };

  const unlink = () => Alert.alert('Отвязать Telegram?', 'Получение ключа и восстановление пароля через этого бота станут недоступны до новой привязки.', [
    { text: 'Отмена', style: 'cancel' },
    { text: 'Отвязать', style: 'destructive', onPress: async () => {
      if (actionBusy.current) return;
      actionBusy.current = true; setBusy(true); setError('');
      try {
        await client.delete('/account/telegram'); pendingLink.current = null;
        setTelegram(old => ({ ...old, linked: false }));
      } catch { setError('Не удалось отвязать Telegram. Проверьте соединение и обновите статус.'); }
      finally { actionBusy.current = false; setBusy(false); }
    } },
  ]);

  return <ScrollView style={s.page} contentContainerStyle={s.content}>
    <View style={s.avatar}><AppIcon name="profile" size={36} /></View>
    <Text style={s.name}>{user.name}</Text><Text style={s.meta}>{user.email}</Text><Text style={s.meta}>Роль: {user.role}</Text>
    <Pressable style={s.button} onPress={onOpenWorkTime}><IconLabel name="tasks">Личный табель · смены и параметры</IconLabel></Pressable>
    <ProfileEditor userId={user.id} onSaved={onProfileSaved} />
    <View style={s.card}>
      <IconLabel name="send" color="#fb923c">Telegram</IconLabel>
      <Text style={s.status}>{telegram ? telegram.linked ? 'Привязан' : 'Не привязан' : 'Статус не получен'}</Text>
      <Text style={s.meta}>Получайте месячный ключ командой /key и восстанавливайте пароль командой /reset.</Text>
      {!!error && <Text accessibilityRole="alert" style={s.error}>{error}</Text>}
      {busy ? <ActivityIndicator color="#fb923c" /> : telegram?.linked ? <>
        {!!telegram.bot_url && <Pressable style={s.button} onPress={() => Linking.openURL(telegram.bot_url).catch(() => setError('Не удалось открыть Telegram.'))}><IconLabel name="send">Открыть бота</IconLabel></Pressable>}
        <Pressable style={s.secondary} onPress={unlink}><IconLabel name="close">Отвязать Telegram</IconLabel></Pressable>
      </> : <Pressable style={s.button} onPress={link} disabled={telegram?.available === false}><IconLabel name="send">Привязать Telegram</IconLabel></Pressable>}
      {telegram?.available === false && <Text style={s.meta}>Бот пока не настроен. Обратитесь к администратору.</Text>}
      <Pressable style={s.secondary} disabled={busy} onPress={() => active.current?.refresh()}><IconLabel name="refresh">Обновить статус</IconLabel></Pressable>
      <Text style={s.meta}>При привязке откроется Telegram. Нажмите «Запустить» у бота и вернитесь сюда.</Text>
    </View>
    <Pressable style={s.secondary} onPress={onReauthenticate}><IconLabel name="refresh">Войти заново без удаления черновиков</IconLabel></Pressable>
    <Text style={s.meta}>При выходе черновики и очередь этого пользователя удаляются с устройства.</Text>
    <Pressable style={s.secondary} onPress={onLogout}><IconLabel name="logout">Выйти</IconLabel></Pressable>
    <Text style={s.version}>ТЧ-15 · Версия {Constants.expoConfig?.version || '—'}</Text>
  </ScrollView>;
}

const s = StyleSheet.create({
  page: { flex: 1, backgroundColor: '#0b1018' }, content: { padding: 24, paddingBottom: 48, gap: 12 },
  avatar: { width: 72, height: 72, borderRadius: 24, backgroundColor: '#2b211c', alignItems: 'center', justifyContent: 'center' },
  name: { color: '#fff', fontSize: 24, fontWeight: '700' }, meta: { color: '#9ca3af', fontSize: 14, lineHeight: 21 },
  card: { backgroundColor: '#151b24', borderColor: '#283241', borderWidth: 1, borderRadius: 20, padding: 18, gap: 14, marginVertical: 8 },
  status: { color: '#fff', fontWeight: '700' }, error: { color: '#fca5a5' },
  button: { minHeight: 48, padding: 14, borderRadius: 12, backgroundColor: '#c2410c', justifyContent: 'center' },
  secondary: { minHeight: 48, padding: 14, borderRadius: 12, backgroundColor: '#253041', justifyContent: 'center' },
  version: { color: '#9ca3af', fontSize: 13, textAlign: 'center', marginTop: 12 },
});
