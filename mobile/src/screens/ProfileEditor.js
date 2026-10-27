import { useCallback, useRef, useState } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, Switch, Text, TextInput, View } from 'react-native';
import { useFocusEffect } from '@react-navigation/native';
import { client } from '../api';

export default function ProfileEditor({ userId, onSaved }) {
  const [profile, setProfile] = useState(null);
  const [values, setValues] = useState({});
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const savingRef = useRef(false);
  const active = useRef(null);

  const load = useCallback(async (signal) => {
    setLoading(true); setError('');
    try {
      const { data } = await client.get('/account/profile', { signal });
      if (!signal?.aborted) { setProfile(data); setValues(data.values); }
    } catch (e) {
      if (!signal?.aborted) setError(e.response?.status === 401 ? 'Войдите заново.' : 'Не удалось загрузить профиль. Повторите запрос.');
    } finally { if (!signal?.aborted) setLoading(false); }
  }, []);

  useFocusEffect(useCallback(() => {
    const controller = new AbortController(); active.current = controller;
    load(controller.signal);
    return () => { controller.abort(); active.current = null; };
  }, [userId, load]));

  const save = async () => {
    if (savingRef.current) return;
    savingRef.current = true; setSaving(true); setError(''); setNotice('');
    const controller = active.current;
    const payload = {};
    for (const field of profile.fields) {
      const value = values[field.key];
      payload[field.key] = field.type === 'number' ? (value === '' || value == null ? null : Number(value))
        : field.type === 'windows' ? (value || []) : field.type === 'boolean' ? !!value : value === '' ? null : value;
    }
    try {
      const { data } = await client.patch('/account/profile', payload, { signal: controller?.signal });
      if (controller?.signal.aborted) return;
      setProfile(data); setValues(data.values); setNotice('Профиль сохранён.');
      await onSaved?.(data.user);
    } catch (e) {
      if (controller?.signal.aborted) return;
      const validation = Object.values(e.response?.data?.errors || {}).flat().join('\n');
      setError(validation || (e.response?.status === 401 ? 'Войдите заново.' : 'Не удалось сохранить профиль. Данные оставлены в форме; повторите отправку.'));
    } finally { savingRef.current = false; setSaving(false); }
  };

  const change = (key, value) => { setValues(old => ({ ...old, [key]: value })); setNotice(''); };
  const windowChange = (key, index, part, value) => setValues(old => {
    const windows = Array.from({ length: 3 }, (_, i) => ({ time: '', reason: '', ...(old[key]?.[i] || {}) }));
    windows[index][part] = value;
    return { ...old, [key]: windows };
  });

  return <View style={s.card}>
    <Text style={s.title}>Мой профиль</Text>
    <Text style={s.hint}>Роль, колонну, инструктора и табельный номер назначает администратор.</Text>
    {loading && <ActivityIndicator color="#fb923c" />}
    {!!error && <Text accessibilityRole="alert" style={s.error}>{error}</Text>}
    {!!notice && <Text accessibilityRole="alert" style={s.success}>{notice}</Text>}
    {!profile && !loading && <Pressable style={s.button} onPress={() => load(active.current?.signal)}><Text style={s.white}>Повторить загрузку</Text></Pressable>}
    {profile && <>
      {Object.entries(profile.readonly).map(([label, value]) => <Text key={label} style={s.hint}>{label}: {value || 'Не указано'}</Text>)}
      {profile.fields.map(field => <View key={field.key} style={s.field}>
        <Text style={s.label}>{field.label}{field.type === 'date' ? ' (ГГГГ-ММ-ДД)' : ''}</Text>
        {field.type === 'windows' ? Array.from({ length: 3 }, (_, index) => <View key={index} style={s.row}>
          <TextInput style={[s.input, s.half]} accessibilityLabel={`${field.label}, время ${index + 1}`} editable={!saving && !loading} maxLength={5} placeholder="ЧЧ.ММ" placeholderTextColor="#94a3b8" value={values[field.key]?.[index]?.time || ''} onChangeText={v => windowChange(field.key, index, 'time', v)} />
          <TextInput style={[s.input, s.half]} accessibilityLabel={`${field.label}, основание ${index + 1}`} editable={!saving && !loading} maxLength={8} placeholder="Основание" placeholderTextColor="#94a3b8" value={values[field.key]?.[index]?.reason || ''} onChangeText={v => windowChange(field.key, index, 'reason', v)} />
        </View>) : field.type === 'boolean' ? <Switch accessibilityLabel={field.label} disabled={saving || loading} value={!!values[field.key]} onValueChange={v => change(field.key, v)} /> :
          <TextInput accessibilityLabel={field.label} style={s.input} editable={!saving && !loading} maxLength={field.max_length || (field.type === 'date' ? 10 : undefined)} keyboardType={field.type === 'number' ? 'number-pad' : field.type === 'tel' ? 'phone-pad' : 'default'} value={String(values[field.key] ?? '')} onChangeText={v => change(field.key, v)} />}
      </View>)}
      <Pressable style={s.button} disabled={saving || loading} onPress={save}>{saving ? <ActivityIndicator color="#fff" /> : <Text style={s.white}>Сохранить профиль</Text>}</Pressable>
    </>}
  </View>;
}

const s = StyleSheet.create({
  card: { backgroundColor: '#151b24', borderColor: '#283241', borderWidth: 1, borderRadius: 20, padding: 18, gap: 12 },
  title: { color: '#fff', fontSize: 20, fontWeight: '700' }, hint: { color: '#9ca3af', fontSize: 13, lineHeight: 20 },
  label: { color: '#e5e7eb', marginBottom: 6 }, field: { gap: 6 }, input: { borderWidth: 1, borderColor: '#475569', borderRadius: 10, color: '#fff', padding: 12, minHeight: 46 },
  row: { flexDirection: 'row', gap: 8 }, half: { flex: 1 }, error: { color: '#fca5a5' }, success: { color: '#86efac' },
  button: { backgroundColor: '#c2410c', padding: 14, borderRadius: 12, minHeight: 48, alignItems: 'center', justifyContent: 'center' }, white: { color: '#fff', fontWeight: '600' },
});
