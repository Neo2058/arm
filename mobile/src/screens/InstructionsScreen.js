import { useCallback, useEffect, useRef, useState } from 'react';
import { ActivityIndicator, Alert, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { useFocusEffect } from '@react-navigation/native';
import { client } from '../api';
import { instructionsStorage } from '../instructions/storage';
import { createPending } from '../instructions/pending.cjs';
function Button({ children, onPress, disabled }) { return <Pressable accessibilityRole="button" disabled={disabled} onPress={onPress} style={[s.button,disabled && { opacity: .4 }]}><Text style={s.text}>{children}</Text></Pressable>; }
export default function InstructionsScreen({ user, navigation }) {
  const [categories,setCategories] = useState(null), [error,setError] = useState(''), [pending,setPending] = useState(null), [busy,setBusy] = useState(false);
  const [storageError,setStorageError] = useState('');
  const alive = useRef(false), manager = useRef(null), generation = useRef(0), sending = useRef(false);
  const load = useCallback(async () => {
    const id = ++generation.current;
    try { const { data } = await client.get('/instructions'); if (alive.current && id === generation.current) { setCategories(data.categories); setError(''); } }
    catch (e) { if (alive.current && id === generation.current) { setCategories(null); setError(e.response?.status === 401 ? 'Войдите заново через профиль.' : 'Не удалось загрузить инструктажи. Повторите запрос.'); } }
  }, []);
  useEffect(() => {
    alive.current = true; let own, unsubscribe;
    (async () => {
      const storage = await instructionsStorage(user.id); if (!alive.current) return;
      own = createPending({ storage, send: async p => (await client.post(`/instructions/${p.id}/sign`,p.body)).data });
      manager.current = own; unsubscribe = own.subscribe(() => { if (alive.current) setPending(own.snapshot()); }); await own.load();
      if (alive.current) setPending(own.snapshot());
    })().catch(() => { if (alive.current) setStorageError('Не удалось открыть сохранённое подтверждение. Данные не удалены.'); });
    return () => { alive.current = false; generation.current++; unsubscribe?.(); own?.stop(); manager.current = null; };
  }, [user.id]);
  useFocusEffect(useCallback(() => { load(); return () => { generation.current++; }; },[load]));
  const run = async fn => {
    if (sending.current || !manager.current) return;
    sending.current = true; setBusy(true);
    try { if (await fn()) await load(); } catch (e) { if (alive.current) setError(e.message || 'Не удалось сохранить подтверждение.'); }
    finally { sending.current = false; if (alive.current) setBusy(false); }
  };
  const confirm = doc => Alert.alert('Подтвердить ознакомление?', `«${doc.title}»\nПодтверждайте после прочтения документа.`, [
    { text: 'Отмена', style: 'cancel' }, { text: 'Я ознакомился', onPress: () => run(() => manager.current.confirm(doc)) },
  ]);
  const documents = categories?.flatMap(c => c.documents) || [];
  return <ScrollView style={s.screen} contentContainerStyle={{ padding:16, gap:12 }}>
    <Text style={s.title}>Росписи по инструктажам</Text>
    <Text style={s.meta}>Откройте документ и подтвердите ознакомление. Тест можно пройти отдельно.</Text>
    <Button onPress={load}>Обновить</Button>
    {!!error && <Text accessibilityRole="alert" style={s.error}>{error}</Text>}
    {!!storageError && <Text accessibilityRole="alert" style={s.error}>{storageError}</Text>}
    {!categories && !error && <ActivityIndicator color="#fb923c" />}
    {categories && <Text style={s.text}>Подтверждено {documents.filter(d => d.signed).length} из {documents.length}</Text>}
    {pending?.pending && <View style={s.card}><Text style={s.title}>Сохранённое подтверждение</Text><Text style={s.text}>{pending.pending.title}</Text>
      {!!pending.error && <Text style={s.error}>{pending.error}</Text>}
      <Button disabled={busy} onPress={() => run(() => manager.current.retry())}>{busy ? 'Сохраняем…' : 'Повторить подтверждение'}</Button>
      {[403,404,422].includes(pending.status) && <Button disabled={busy} onPress={() => run(async () => { await manager.current.dismissRejected(); await load(); return false; })}>Закрыть отклонённый запрос</Button>}
    </View>}
    {categories?.map(category => <View key={category.id} style={{ gap:12 }}><Text style={s.title}>{category.name}</Text>
      {category.documents.map(doc => <View key={doc.id} style={s.card}><Text style={s.title}>{doc.title}</Text>
        <Text style={s.meta}>{doc.signed ? `Ознакомление подтверждено · ${new Date(doc.signed_at).toLocaleString('ru-RU')}` : 'Требуется ознакомление'}</Text>
        <Button disabled={!doc.has_file} onPress={() => navigation.navigate('DocumentView',{ id:doc.id,title:doc.title })}>Открыть документ</Button>
        {doc.quiz_id && <Button onPress={() => navigation.navigate('InstructionQuiz',{ id:doc.quiz_id,title:doc.title })}>Пройти тест</Button>}
        {!doc.signed && <Button disabled={!pending || busy || !!pending.pending || !doc.has_file} onPress={() => confirm(doc)}>Подтвердить ознакомление</Button>}
      </View>)}
    </View>)}
    {categories?.length === 0 && <Text style={s.meta}>Доступных инструктажей пока нет.</Text>}
  </ScrollView>;
}
const s = StyleSheet.create({ screen:{ flex:1,backgroundColor:'#0b1018' },card:{ backgroundColor:'#141e2e',padding:16,borderRadius:16,gap:10 },title:{ color:'#fff',fontSize:18,fontWeight:'600' },text:{ color:'#fff',fontSize:15 },meta:{ color:'#a7b3c5',fontSize:14 },error:{ color:'#fca5a5' },button:{ padding:12,borderWidth:1,borderColor:'#52637b',borderRadius:12 } });
