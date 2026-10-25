import { useCallback, useEffect, useRef, useState } from 'react';
import {
  ActivityIndicator,
  FlatList,
  Linking,
  Pressable,
  RefreshControl,
  StyleSheet,
  Text,
  TextInput,
  View,
} from 'react-native';
import { client } from '../api';
import { IconLabel } from '../components/AppIcon';

export default function PhonesScreen() {
  const [phones, setPhones] = useState([]);
  const [query, setQuery] = useState('');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [name, setName] = useState('');
  const [number, setNumber] = useState('');
  const [saving, setSaving] = useState(false);
  const [saveError, setSaveError] = useState('');
  const [notice, setNotice] = useState('');
  const [page, setPage] = useState(1);
  const [more, setMore] = useState(false);
  const sequence = useRef(0);
  const saveLock = useRef(false);

  const load = useCallback(async (q, next = 1) => {
    const id = ++sequence.current;
    setLoading(true);
    setError('');
    try {
      const { data } = await client.get('/phones', { params: { q, page: next } });
      if (id !== sequence.current) return;
      setPhones(prev => next === 1 ? data.phones || [] : [...prev, ...(data.phones || [])]);
      setPage(next); setMore(Boolean(data.has_more));
    } catch {
      if (id === sequence.current) setError('Не удалось загрузить телефоны. Проверьте сеть и вход.');
    } finally {
      if (id === sequence.current) setLoading(false);
    }
  }, []);

  useEffect(() => {
    sequence.current++;
    const wait = query.trim() ? 300 : 0;
    const handle = setTimeout(() => {
      load(query.trim());
    }, wait);
    return () => { clearTimeout(handle); sequence.current++; };
  }, [query, load]);

  const add = async () => {
    if (saveLock.current) return;
    if (!name.trim() || !number.trim()) { setSaveError('Укажите ФИО и телефон.'); return; }
    saveLock.current = true; setSaving(true); setSaveError(''); setNotice('');
    try {
      await client.post('/phones', { name: name.trim(), phone: number.trim() });
      setName(''); setNumber(''); setQuery('');
      setNotice('Контакт сохранён и доступен всем пользователям.');
      await load('');
    } catch (e) {
      const status = e.response?.status;
      setSaveError(Object.values(e.response?.data?.errors || {}).flat()[0] ||
        (status === 429 ? 'Слишком много добавлений. Подождите минуту.' : 'Не удалось сохранить. Проверьте сеть и вход. Введённые данные сохранены.'));
    } finally { saveLock.current = false; setSaving(false); }
  };

  const call = (number) => {
    if (!number) return;
    const tel = number.replace(/[^\d+]/g, '');
    Linking.openURL(`tel:${tel}`).catch(() => {});
  };

  return (
    <View style={styles.wrap}>
      <TextInput
        style={styles.search}
        placeholder="Поиск по ФИО или номеру"
        placeholderTextColor="#6b7280"
        value={query}
        onChangeText={setQuery}
        autoCorrect={false}
      />
      {!!error && <Text accessibilityRole="alert" style={styles.error}>{error}</Text>}
      {loading && phones.length === 0 ? (
        <ActivityIndicator color="#fb923c" style={{ marginTop: 24 }} />
      ) : (
        <FlatList
          data={phones}
          keyExtractor={(item) => String(item.id)}
          contentContainerStyle={{ padding: 16, paddingBottom: 40 }}
          keyboardShouldPersistTaps="handled"
          refreshControl={<RefreshControl refreshing={loading} onRefresh={() => load(query.trim())} />}
          ListHeaderComponent={<View style={styles.card}>
            <Text style={styles.name}>Добавить телефон машиниста</Text>
            <Text style={styles.hint}>ФИО и номер будут видны всем пользователям приложения и веба.</Text>
            <TextInput accessibilityLabel="ФИО машиниста" style={styles.input} placeholder="ФИО" placeholderTextColor="#9ca3af" value={name} onChangeText={setName} maxLength={160} editable={!saving} />
            <TextInput accessibilityLabel="Телефон машиниста" style={styles.input} placeholder="+7 (999) 123-45-67" placeholderTextColor="#9ca3af" value={number} onChangeText={setNumber} maxLength={40} keyboardType="phone-pad" editable={!saving} />
            <Pressable accessibilityRole="button" disabled={saving} onPress={add} style={[styles.button, saving && { opacity: 0.5 }]}><Text style={styles.buttonText}>{saving ? 'Сохраняем…' : 'Добавить в общий справочник'}</Text></Pressable>
            {!!saveError && <Text accessibilityRole="alert" style={styles.error}>{saveError}</Text>}
            {!!notice && <Text style={styles.success}>{notice}</Text>}
          </View>}
          ListFooterComponent={more ? <Pressable accessibilityRole="button" disabled={loading} style={styles.button} onPress={() => load(query.trim(), page + 1)}><Text style={styles.buttonText}>{loading ? 'Загрузка…' : 'Показать ещё'}</Text></Pressable> : null}
          ListEmptyComponent={<Text style={styles.muted}>{error || 'Номеров нет'}</Text>}
          renderItem={({ item }) => (
            <View style={styles.card}>
              <Text style={styles.name}>{item.name}</Text>
              {item.position ? <Text style={styles.meta}>{item.position}</Text> : null}
              {item.phone ? (
                <Pressable onPress={() => call(item.phone)}>
                  <IconLabel name="phone" color="#fb923c" textStyle={styles.phone} style={{ justifyContent: 'flex-start', minHeight: 44 }}>{item.phone}</IconLabel>
                </Pressable>
              ) : null}
              {item.phone2 ? (
                <Pressable onPress={() => call(item.phone2)}>
                  <IconLabel name="phone" color="#fb923c" textStyle={styles.phone} style={{ justifyContent: 'flex-start', minHeight: 44 }}>{item.phone2}</IconLabel>
                </Pressable>
              ) : null}
            </View>
          )}
        />
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  wrap: { flex: 1, backgroundColor: '#0b1018' },
  input: { backgroundColor: '#0b1018', color: '#fff', borderRadius: 12, padding: 12, marginTop: 12 },
  hint: { color: '#d1d5db', marginTop: 8 },
  button: { backgroundColor: '#c2410c', padding: 14, borderRadius: 12, marginTop: 12, minHeight: 48 },
  buttonText: { color: '#fff', textAlign: 'center', fontWeight: '600' },
  error: { color: '#fca5a5', margin: 12 },
  success: { color: '#86efac', marginTop: 12 },
  search: {
    margin: 16,
    marginBottom: 0,
    backgroundColor: '#151b24',
    color: '#fff',
    borderRadius: 12,
    paddingHorizontal: 14,
    paddingVertical: 12,
  },
  card: { backgroundColor: '#151b24', borderRadius: 16, padding: 16, marginBottom: 12 },
  name: { color: '#fff', fontSize: 16, fontWeight: '600' },
  meta: { color: '#6b7280', marginTop: 4 },
  phone: { color: '#fb923c', marginTop: 8, fontSize: 16 },
  muted: { color: '#9ca3af', textAlign: 'center', marginTop: 40 },
});
