import { useCallback, useRef, useState } from 'react';
import { ActivityIndicator, Alert, AppState, KeyboardAvoidingView, Platform, Pressable, RefreshControl, ScrollView, Text, TextInput, View } from 'react-native';
import { useFocusEffect } from '@react-navigation/native';
import * as DocumentPicker from 'expo-document-picker';
import * as Crypto from 'expo-crypto';
import { getAssignment, getReports, markRead, submitReport } from '../discipline/api';
import { CATEGORIES, LIMITS, TYPES, errorMessage, freezeSubmission, validateDraft, validateFiles } from '../discipline/contract.cjs';
import s from '../discipline/styles';

const dateTime = value => value ? new Date(value).toLocaleString('ru-RU') : '';
function mergeInfo(old, fresh) {
  const reports = new Map((old?.reports || []).map(report => [report.id, report]));
  for (const report of fresh.reports || []) reports.set(report.id, report);
  return { ...old, ...fresh, reports: [...reports.values()].sort((a, b) => b.attempt - a.attempt) };
}

export default function DisciplineDetailScreen({ route, navigation }) {
  const id = String(route.params.id);
  const [info, setInfo] = useState(null);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [response, setResponse] = useState('');
  const [files, setFiles] = useState([]);
  const [pending, setPending] = useState(null);
  const [sending, setSending] = useState(false);
  const [refreshing, setRefreshing] = useState(false);
  const [historyBusy, setHistoryBusy] = useState(false);
  const active = useRef(null);
  const pendingRef = useRef(null);
  const inFlight = useRef(false);

  useFocusEffect(useCallback(() => {
    const controller = new AbortController();
    active.current = { controller, busy: false, sequence: 0 };
    const refresh = async () => {
      const request = active.current;
      if (request?.controller !== controller || request.busy || inFlight.current) return;
      request.busy = true; const sequence = ++request.sequence; setRefreshing(true);
      try {
        const data = await getAssignment(id, controller.signal);
        if (controller.signal.aborted || sequence !== request.sequence) return;
        setInfo(old => mergeInfo(old, { ...data, reports_pagination: old && old.reports[0]?.id === data.reports[0]?.id ? old.reports_pagination : data.reports_pagination })); setError('');
        markRead(id).catch(() => {});
      } catch (e) { if (!controller.signal.aborted) setError(errorMessage(e)); }
      finally { if (!controller.signal.aborted) { request.busy = false; setRefreshing(false); } }
    };
    active.current.refresh = refresh;
    refresh();
    const timer = setInterval(() => { if (AppState.currentState === 'active') refresh(); }, 30000);
    const sub = AppState.addEventListener('change', state => { if (state === 'active') refresh(); });
    return () => { controller.abort(); clearInterval(timer); sub.remove(); };
  }, [id]));

  const chooseFiles = async category => {
    if (pendingRef.current || inFlight.current) return;
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
      setFiles(next); setNotice('');
    } catch { Alert.alert('Выбор файла', 'Не удалось прочитать выбранный файл. Повторите выбор.'); }
  };

  const send = async () => {
    if (inFlight.current) return;
    if (!pendingRef.current) {
      const message = validateDraft(info.assignment, response, files, info.limits || LIMITS);
      if (message) { setError(message); return; }
      pendingRef.current = freezeSubmission(info.assignment, response, files, Crypto.randomUUID());
      setPending(pendingRef.current);
    }
    const snapshot = pendingRef.current;
    inFlight.current = true; setSending(true); setError(''); setNotice('');
    // A refresh started before submission must not overwrite its newer result.
    if (active.current) active.current.sequence += 1;
    try {
      const data = await submitReport(snapshot);
      setInfo(old => mergeInfo(old, data)); setResponse(''); setFiles([]);
      pendingRef.current = null; setPending(null);
      setNotice('Отчёт получен сервером. Он будет завершён после принятия руководителем.');
    } catch (e) {
      setError(errorMessage(e));
      if ([409, 412, 413, 422].includes(e?.response?.status)) {
        pendingRef.current = null; setPending(null);
        try { const fresh = await getAssignment(id); setInfo(old => mergeInfo(old, fresh)); } catch { /* preserve the local response */ }
      }
    } finally {
      inFlight.current = false; setSending(false);
    }
  };

  const moreHistory = async () => {
    if (historyBusy || !info?.reports_pagination.next_page) return;
    setHistoryBusy(true);
    try {
      const next = await getReports(id, info.reports_pagination.next_page);
      setInfo(old => mergeInfo(old, next));
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
      {!!notice && <Text accessibilityRole="alert" style={s.success}>{notice}</Text>}
      {(editable || pending) && <View style={s.card}>
        <Text style={s.heading}>Отправить отчёт</Text>
        <TextInput accessibilityLabel="Текст отчёта" placeholder="Опишите выполненную работу" placeholderTextColor="#9ca3af" style={s.input} value={response} onChangeText={setResponse} multiline maxLength={20000} editable={!pending && !sending} />
        <Text style={s.muted}>PDF, JPEG, PNG. До 10 МиБ на файл, максимум пять файлов и 25 МиБ суммарно.</Text>
        {Object.entries(CATEGORIES).map(([category, label]) => <Pressable key={category} style={[s.secondary, !!pending && s.disabled]} disabled={!!pending || sending} onPress={() => chooseFiles(category)}><Text style={s.buttonText}>+ {label}</Text></Pressable>)}
        {files.map(file => <View key={file.id} style={s.card}><Text style={s.body}>{CATEGORIES[file.category]}: {file.name}</Text><Text style={s.muted}>{(file.size / 1048576).toFixed(2)} МиБ</Text>{!pending && <Pressable accessibilityRole="button" onPress={() => setFiles(old => old.filter(f => f.id !== file.id))}><Text style={s.accent}>Убрать</Text></Pressable>}</View>)}
        {!!pending && <Text style={s.muted}>Содержимое запроса зафиксировано до ответа сервера. Повтор проверит ту же отправку без дубликата. До подтверждения не закрывайте приложение.</Text>}
        <Pressable accessibilityRole="button" style={[s.button, sending && s.disabled]} disabled={sending} onPress={send}>{sending ? <ActivityIndicator color="#fff" /> : <Text style={s.buttonText}>{pending ? 'Повторить ту же отправку' : 'Отправить на проверку'}</Text>}</Pressable>
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
