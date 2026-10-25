import { useCallback, useRef, useState } from 'react';
import { ActivityIndicator, Alert, AppState, KeyboardAvoidingView, Platform, Pressable, RefreshControl, ScrollView, Text, TextInput, View } from 'react-native';
import { useFocusEffect } from '@react-navigation/native';
import * as DocumentPicker from 'expo-document-picker';
import * as Crypto from 'expo-crypto';
import { getAssignment, getReports, markRead } from '../discipline/api';
import { CATEGORIES, LIMITS, TYPES, errorMessage, validateFiles } from '../discipline/contract.cjs';
import s from '../discipline/styles';
import { IconLabel } from '../components/AppIcon';
import { useDiscipline } from '../discipline/context';

const dateTime = value => value ? new Date(value).toLocaleString('ru-RU') : '';
function mergeInfo(old, fresh) {
  const reports = new Map((old?.reports || []).map(report => [report.id, report]));
  for (const report of fresh.reports || []) reports.set(report.id, report);
  return { ...old, ...fresh, reports: [...reports.values()].sort((a, b) => b.attempt - a.attempt) };
}

export default function DisciplineDetailScreen({ route, navigation }) {
  const id = String(route.params.id);
  const { queue, state } = useDiscipline();
  const entry = state.entries[id];
  const info = entry?.info;
  const response = entry?.response || '';
  const files = entry?.files || [];
  const pending = entry?.pending;
  const [error, setError] = useState('');
  const [sending, setSending] = useState(false);
  const [refreshing, setRefreshing] = useState(false);
  const [historyBusy, setHistoryBusy] = useState(false);
  const [offline, setOffline] = useState(false);
  const active = useRef(null);

  useFocusEffect(useCallback(() => {
    const controller = new AbortController();
    active.current = { controller, busy: false, sequence: 0 };
    const refresh = async () => {
      const request = active.current;
      if (request?.controller !== controller || request.busy) return;
      request.busy = true; const sequence = ++request.sequence; setRefreshing(true);
      try {
        const data = await getAssignment(id, controller.signal);
        if (controller.signal.aborted || sequence !== request.sequence) return;
        const old = queue.snapshot().entries[id]?.info;
        await queue.cache(mergeInfo(old, { ...data, reports_pagination: old && old.reports[0]?.id === data.reports[0]?.id ? old.reports_pagination : data.reports_pagination }));
        setError(''); setOffline(false);
        markRead(id).catch(() => {});
      } catch (e) { if (!controller.signal.aborted) { setError(errorMessage(e)); setOffline(true); } }
      finally { if (!controller.signal.aborted) { request.busy = false; setRefreshing(false); } }
    };
    active.current.refresh = refresh;
    refresh();
    const timer = setInterval(() => { if (AppState.currentState === 'active') refresh(); }, 30000);
    const sub = AppState.addEventListener('change', state => { if (state === 'active') refresh(); });
    return () => { controller.abort(); clearInterval(timer); sub.remove(); };
  }, [id, queue]));

  const chooseFiles = async category => {
    if (pending) return;
    try {
      const result = await DocumentPicker.getDocumentAsync({ type: Object.keys(TYPES), multiple: true, copyToCacheDirectory: true });
      if (result.canceled || active.current?.controller.signal.aborted) return;
      const chosen = result.assets.map(asset => ({
        id: Crypto.randomUUID(), uri: asset.uri, name: asset.name, size: asset.size,
        mime: asset.mimeType || Object.keys(TYPES).find(type => TYPES[type].includes(asset.name.split('.').pop().toLowerCase())), category,
      }));
      const next = [...files, ...chosen];
      const message = validateFiles(next, info.limits || LIMITS);
      if (message) { Alert.alert('Проверьте приложения', message); return; }
      await queue.addFiles(id, chosen);
    } catch { Alert.alert('Выбор файла', 'Не удалось прочитать выбранный файл. Повторите выбор.'); }
  };

  const send = async () => {
    if (sending) return;
    setSending(true); setError('');
    try {
      if (pending) await queue.retry(id); else await queue.enqueue(id);
      await queue.flush();
    } catch (e) { setError(e.message || 'Не удалось сохранить отправку на устройстве.'); }
    finally { setSending(false); }
  };
  const unlock = async () => {
    try { await queue.cache(await getAssignment(id)); await queue.unlock(id); setError(''); }
    catch (e) { setError(errorMessage(e)); }
  };
  const edit = async value => { try { await queue.edit(id, value); } catch { setError('Не удалось сохранить черновик. Повторите ввод.'); } };
  const remove = async fileId => { try { await queue.removeFile(id, fileId); } catch { setError('Не удалось сохранить изменение вложений.'); } };

  const moreHistory = async () => {
    if (historyBusy || !info?.reports_pagination.next_page) return;
    setHistoryBusy(true);
    try {
      const next = await getReports(id, info.reports_pagination.next_page);
      await queue.cache(mergeInfo(queue.snapshot().entries[id]?.info, next));
    } catch (e) { setError(errorMessage(e)); }
    finally { setHistoryBusy(false); }
  };

  if (!info) return <View style={s.center}>{refreshing ? <ActivityIndicator color="#fb923c" /> : <><Text style={s.error}>{error}</Text><Pressable style={s.secondary} onPress={() => active.current?.refresh()}><Text style={s.buttonText}>Повторить загрузку</Text></Pressable></>}</View>;
  const a = info.assignment;
  const editable = ['assigned', 'needs_revision'].includes(a.status);
  return <KeyboardAvoidingView style={s.page} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
    <ScrollView keyboardShouldPersistTaps="handled" contentContainerStyle={s.content} refreshControl={<RefreshControl refreshing={refreshing} tintColor="#fb923c" onRefresh={() => active.current?.refresh()} />}>
      <View style={s.card}>
        <Text style={s.title}>{a.title}</Text><Text style={s.accent}>{a.status_label}</Text>
        <Text style={s.muted}>Срок: {a.due_date.split('-').reverse().join('.')} · {a.author.name}</Text>
        {a.is_overdue && <Text style={s.error}>Просрочено</Text>}
        <Text selectable style={s.body}>{a.instructions}</Text>
        <Text style={s.muted}>Обязательно: {[a.requirements.require_response && 'текстовый ответ', a.requirements.require_report_file && 'файл отчёта', a.requirements.require_explanation_file && 'файл объяснения'].filter(Boolean).join(', ') || 'подтверждение выполнения'}.</Text>
      </View>
      {!!error && <Text accessibilityRole="alert" style={s.error}>{error}</Text>}
      {offline && <Text style={s.muted}>Сохранённая копия. Данные будут обновлены при восстановлении связи.</Text>}
      {!!entry?.error && <Text style={s.error}>{entry.error}</Text>}
      {entry?.status === 'sent' && a.status === 'in_review' && <Text style={s.success}>Отчёт получен сервером. Ожидайте решения руководителя.</Text>}
      {entry?.status === 'queued' && <Text style={s.muted}>Отчёт сохранён в очереди. Автоматический повтор при доступном интернете, пока приложение открыто.</Text>}
      {entry?.status === 'draft' && <Text style={s.muted}>Черновик сохраняется на этом устройстве.</Text>}
      {(editable || pending) && <View style={s.card}>
        <Text style={s.heading}>Отправить отчёт</Text>
        <TextInput accessibilityLabel="Текст отчёта" placeholder="Опишите выполненную работу" placeholderTextColor="#9ca3af" style={s.input} key={pending?.operation_id || (entry.status === 'sent' ? 'sent' : 'draft')} defaultValue={response} onChangeText={edit} multiline maxLength={20000} editable={!pending && !sending} />
        <Text style={s.muted}>PDF, JPEG, PNG. До 10 МиБ на файл, максимум пять файлов и 25 МиБ суммарно.</Text>
        {Object.entries(CATEGORIES).map(([category, label]) => <Pressable key={category} style={[s.secondary, !!pending && s.disabled]} disabled={!!pending || sending} onPress={() => chooseFiles(category)}><IconLabel name="attach" textStyle={s.buttonText}>{label}</IconLabel></Pressable>)}
        {files.map(file => <View key={file.id} style={s.card}><Text style={s.body}>{CATEGORIES[file.category]}: {file.name}</Text><Text style={s.muted}>{(file.size / 1048576).toFixed(2)} МиБ</Text>{!pending && <Pressable accessibilityRole="button" onPress={() => remove(file.id)}><Text style={s.accent}>Убрать</Text></Pressable>}</View>)}
        {!!pending && <Text style={s.muted}>Содержимое запроса зафиксировано до ответа сервера. Повтор проверит ту же отправку без дубликата. Запрос сохранён на устройстве и восстановится после перезапуска.</Text>}
        {entry?.status === 'blocked' && [409, 412, 413, 422].includes(entry.httpStatus) ? <Pressable style={s.button} onPress={unlock}><Text style={s.buttonText}>Обновить карточку и исправить отчёт</Text></Pressable> : <Pressable accessibilityRole="button" style={[s.button, sending && s.disabled]} disabled={sending} onPress={send}>{sending ? <ActivityIndicator color="#fff" /> : <Text style={s.buttonText}>{pending ? 'Повторить ту же отправку' : 'Сохранить и отправить'}</Text>}</Pressable>}
      </View>}
      <Text style={s.heading}>История отчётов</Text>
      {!info.reports.length && <Text style={s.muted}>Отчёты ещё не отправлялись.</Text>}
      {info.reports.map(report => <View key={report.id} style={s.card}>
        <Text style={s.heading}>Попытка №{report.attempt}</Text><Text style={s.muted}>{dateTime(report.submitted_at)} · время устройства</Text>
        {!!report.response && <Text selectable style={s.body}>{report.response}</Text>}
        {report.files.map(file => <Pressable key={file.id} style={s.secondary} onPress={() => navigation.navigate('DisciplineAttachment', { file })}><Text style={s.buttonText}>{CATEGORIES[file.category]}: {file.name}</Text></Pressable>)}
        <Text style={report.decision === 'accepted' ? s.success : s.accent}>{report.decision === 'accepted' ? 'Принят руководителем' : report.decision === 'returned' ? 'Возвращён на доработку' : 'Ожидает проверки'}</Text>
        {!!report.review_comment && <Text selectable style={s.body}>{report.review_comment}</Text>}
        {!!report.reviewed_at && <Text style={s.muted}>{report.reviewer_name} · {dateTime(report.reviewed_at)}</Text>}
      </View>)}
      {!!info.reports_pagination.next_page && <Pressable style={s.secondary} disabled={historyBusy} onPress={moreHistory}><Text style={s.buttonText}>{historyBusy ? 'Загрузка…' : 'Показать более ранние отчёты'}</Text></Pressable>}
    </ScrollView>
  </KeyboardAvoidingView>;
}
