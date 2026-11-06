import { useEffect, useRef, useState } from 'react';
import { ActivityIndicator, AppState, Pressable, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';
import { SafeAreaProvider, SafeAreaView } from 'react-native-safe-area-context';
import * as SecureStore from 'expo-secure-store';
import axios from 'axios';
import { API_URL } from '../api';

const api = axios.create({ baseURL: API_URL.replace(/\/mobile\/?$/, '/monitor'), timeout: 15000, headers: { Accept: 'application/json' } });
const TOKEN = 'monitor-token';
const fmt = value => value === undefined ? '—' : Number(value).toLocaleString('ru-RU', { maximumFractionDigits: 1 });
const alerts = { cpu: 'Высокая загрузка процессора', memory: 'Мало свободной памяти', disk: 'Мало места на диске', failed_jobs: 'Есть неуспешные задания', backup_old: 'Резервная копия устарела', web: 'Ресурс недоступен', database: 'Не удалось проверить базу', queue_lag: 'Задержка очереди' };

export default function MonitorApp() {
  const [token, setToken] = useState(null), [ready, setReady] = useState(false);
  const [email, setEmail] = useState(''), [password, setPassword] = useState('');
  const [data, setData] = useState(null), [error, setError] = useState(''), [busy, setBusy] = useState(false);
  const generation = useRef(0);
  useEffect(() => { SecureStore.getItemAsync(TOKEN).then(setToken).catch(() => setError('Не удалось открыть защищённое хранилище.')).finally(() => setReady(true)); }, []);
  useEffect(() => {
    if (!token) return;
    const current = ++generation.current; let pending = false;
    const refresh = async () => {
      if (pending || AppState.currentState !== 'active') return;
      pending = true;
      try {
        const response = await api.get('/snapshot', { headers: { Authorization: `Bearer ${token}` } });
        if (generation.current === current) { setData(response.data); setError(''); }
      } catch (e) {
        if (generation.current !== current) return;
        if ([401, 403].includes(e.response?.status)) {
          await SecureStore.deleteItemAsync(TOKEN); setToken(null); setData(null);
          setError('Войдите заново. Требуется активная роль суперадминистратора.');
        } else { setData(null); setError('Свежие показатели недоступны. Проверьте соединение.'); }
      } finally { pending = false; }
    };
    refresh(); const timer = setInterval(refresh, 30000);
    const listener = AppState.addEventListener('change', state => { if (state === 'active') refresh(); else setData(null); });
    return () => { generation.current++; clearInterval(timer); listener.remove(); };
  }, [token]);
  const login = async () => {
    if (busy) return; setBusy(true); setError('');
    try {
      const { data: result } = await api.post('/login', { email: email.trim(), password });
      if (typeof result.token !== 'string' || !result.token) throw new Error('Invalid response');
      await SecureStore.setItemAsync(TOKEN, result.token); setPassword(''); setToken(result.token);
    } catch { setError('Вход не выполнен. Проверьте сеть, пароль и права суперадминистратора.'); }
    finally { setBusy(false); }
  };
  const logout = async () => {
    if (busy) return; setBusy(true); generation.current++;
    try { await api.post('/logout', {}, { headers: { Authorization: `Bearer ${token}` } }); } catch {}
    try { await SecureStore.deleteItemAsync(TOKEN); setToken(null); setData(null); setError(''); }
    catch { setError('Не удалось удалить сохранённый вход. Повторите выход.'); }
    finally { setBusy(false); }
  };
  const metric = (name, label = '') => data?.metrics?.find(m => m.name === 'arm_' + name && m.label === label)?.value;
  const card = (title, value, detail) => <View key={title} style={s.card}><Text style={s.label}>{title}</Text><Text style={s.value}>{value}</Text>{!!detail && <Text style={s.muted}>{detail}</Text>}</View>;
  const gb = n => n === undefined ? '—' : fmt(n / 1073741824) + ' ГБ';
  return <SafeAreaProvider><SafeAreaView style={s.page}><ScrollView contentContainerStyle={s.content} keyboardShouldPersistTaps="handled">
    <Text style={s.eyebrow}>ТЧ-15 · ТОЛЬКО ДЛЯ СУПЕРАДМИНИСТРАТОРОВ</Text>
    <Text style={s.title}>Пульс ресурса</Text><Text style={s.muted}>Наблюдение за сервером и приложением</Text>
    {!!error && <Text style={s.error} accessibilityRole="alert">{error}</Text>}
    {!ready ? <ActivityIndicator /> : !token ? <View style={s.card}>
      <Text style={s.label}>Вход с учётной записью ТЧ-15</Text>
      <TextInput style={s.input} value={email} onChangeText={setEmail} placeholder="Email" placeholderTextColor="#8592a5" autoCapitalize="none" autoCorrect={false} keyboardType="email-address" autoComplete="username" />
      <TextInput style={s.input} value={password} onChangeText={setPassword} placeholder="Пароль" placeholderTextColor="#8592a5" secureTextEntry autoCapitalize="none" autoCorrect={false} autoComplete="current-password" />
      <Pressable disabled={busy} style={s.button} onPress={login}><Text style={s.buttonText}>{busy ? 'Подключение…' : 'Войти'}</Text></Pressable>
    </View> : <>
      {!data ? <ActivityIndicator color="#fb923c" /> : <>
        <Text style={data.stale ? s.error : s.good}>{data.stale ? 'Данные устарели — состояние требует проверки' : 'Обновляется каждые 30 секунд'}</Text>
        <Text style={s.muted}>Снимок: {new Date(data.collected_at * 1000).toLocaleString('ru-RU')}</Text>
        {card('Доступность ресурса', metric('http_probe_up') === undefined ? 'Нет данных' : metric('http_probe_up') === 1 ? 'Доступен' : 'Нет ответа', 'Проверка страницы входа с сервера')}
        {card('Процессор', fmt(metric('host_cpu_percent')) + ' %')}
        {card('Свободная память', gb(metric('host_memory_bytes', 'MemAvailable')), 'Всего: ' + gb(metric('host_memory_bytes', 'MemTotal')))}
        {card('Свободное место', gb(metric('host_disk_bytes', 'free')), 'Всего: ' + gb(metric('host_disk_bytes', 'total')))}
        {card('Очередь', fmt(metric('queue_pending')), 'Неуспешных заданий: ' + fmt(metric('queue_failed')))}
        {card('Соединения с базой', fmt(metric('db_connections')), 'Максимум: ' + fmt(metric('db_max_connections')))}
        {card('Серверная резервная копия', metric('backup_created_timestamp_seconds') ? new Date(metric('backup_created_timestamp_seconds') * 1000).toLocaleDateString('ru-RU') : 'Не найдена', 'Не подтверждает наличие копии на вашем ПК')}
        <Text style={s.heading}>Сервисы</Text>
        {data.metrics.filter(m => m.name === 'arm_container_up').map(m => card(m.label.replace(/^arm-/, ''), m.value === 1 && metric('container_healthy', m.label) === 1 ? 'Работает' : 'Требует проверки', 'CPU: ' + fmt(metric('container_cpu_percent', m.label)) + ' %'))}
        <Text style={s.heading}>Предупреждения</Text>
        {data.metrics.filter(m => m.name === 'arm_alert_active' && m.value === 1).map(m => <Text key={m.label} style={s.error}>• {alerts[m.label] || 'Требует проверки: ' + m.label}</Text>)}
        {data.metrics.filter(m => m.name === 'arm_collection_stage_up' && m.value !== 1).map(m => <Text key={m.label} style={s.error}>Нет свежей проверки: {m.label}</Text>)}
        <Text style={s.muted}>При потере связи с сервером показатели недоступны. Оповещения дополнительно поступают в Telegram.</Text>
      </>}
      <Pressable style={s.button} disabled={busy} onPress={logout}><Text style={s.buttonText}>Выйти</Text></Pressable>
    </>}
    <Text style={s.muted}>Мониторинг 1.0.0 · Без команд управления сервером</Text>
  </ScrollView></SafeAreaView></SafeAreaProvider>;
}
const s = StyleSheet.create({ page: { flex: 1, backgroundColor: '#0b1018' }, content: { padding: 22, gap: 14 }, eyebrow: { color: '#fb923c', fontSize: 10, letterSpacing: 1 }, title: { color: '#fff', fontSize: 32, fontWeight: '700' }, heading: { color: '#fff', fontSize: 22, marginTop: 10 }, muted: { color: '#9daabb', lineHeight: 21 }, card: { padding: 18, gap: 10, borderRadius: 18, backgroundColor: '#162131', borderWidth: 1, borderColor: '#28394e' }, label: { color: '#afbdcf', fontSize: 15 }, value: { color: '#fff', fontSize: 25, fontWeight: '600' }, error: { color: '#fca5a5', lineHeight: 22 }, good: { color: '#86efac' }, input: { color: '#fff', borderColor: '#42526a', borderWidth: 1, borderRadius: 10, padding: 14 }, button: { backgroundColor: '#c2410c', borderRadius: 12, padding: 16, alignItems: 'center' }, buttonText: { color: '#fff', fontWeight: '600' } });
