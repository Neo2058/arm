import { useCallback, useEffect, useMemo, useState } from 'react';
import {
  ActivityIndicator,
  FlatList,
  KeyboardAvoidingView,
  Platform,
  Pressable,
  StyleSheet,
  Text,
  TextInput,
  View,
} from 'react-native';
import { useFocusEffect } from '@react-navigation/native';
import { listChatMessages } from '../api';
import { getChatOutbox, getChatPoller, whenChatSession } from '../chatPoller';

function formatTime(iso) {
  if (!iso) return '';
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return '';
  const pad = (n) => String(n).padStart(2, '0');
  return `${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

function bodyOf(item) {
  if (item.type === 'deleted') return 'Сообщение удалено';
  if (item.type === 'image') return 'Фото';
  if (item.type === 'pdf') return 'PDF';
  if (item.type === 'system') return item.body || 'Системное';
  return item.body || '';
}

function threadRows(roomId, pollerState, outboxItems, userId) {
  const server = (pollerState.messagesByRoom && pollerState.messagesByRoom[roomId]) || [];
  const visible = server.filter((msg) => msg.status !== 'rejected');
  const seen = new Set(visible.map((msg) => msg.client_id).filter(Boolean));
  const local = (outboxItems || [])
    .filter((item) => item.room_id === roomId && !seen.has(item.client_id))
    .map((item) => ({
      id: `local-${item.client_id}`,
      local: true,
      client_id: item.client_id,
      type: item.type,
      body: item.body,
      sender_id: userId,
      status: item.status,
      error: item.error,
      httpStatus: item.httpStatus,
      created_at: item.created_at,
    }));
  return [...visible, ...local];
}

export default function ChatThreadScreen({ route }) {
  const roomId = route.params.id;
  const [session, setSession] = useState(null);
  const [pollerState, setPollerState] = useState({ messagesByRoom: {}, offline: false, userId: null });
  const [outboxItems, setOutboxItems] = useState([]);
  const [text, setText] = useState('');
  const [hasOlder, setHasOlder] = useState(true);
  const [loadingOlder, setLoadingOlder] = useState(false);

  useEffect(() => whenChatSession(setSession), []);

  useEffect(() => {
    if (!session?.poller) return undefined;
    const stopPoller = session.poller.subscribe(setPollerState);
    const stopOutbox = session.outbox ? session.outbox.subscribe((state) => setOutboxItems(state.items || [])) : () => {};
    return () => { stopPoller(); stopOutbox(); };
  }, [session]);

  useFocusEffect(useCallback(() => {
    const poller = getChatPoller();
    poller?.setFocusedRoomId(roomId);
    return () => {
      const current = getChatPoller();
      if (current?.getState().focusedRoomId === roomId) current.setFocusedRoomId(null);
    };
  }, [roomId]));

  const userId = pollerState.userId;
  const rows = useMemo(
    () => threadRows(roomId, pollerState, outboxItems, userId),
    [roomId, pollerState, outboxItems, userId],
  );
  const data = useMemo(() => rows.slice().reverse(), [rows]);

  const loadOlder = async () => {
    const poller = getChatPoller();
    const oldest = ((poller?.getState().messagesByRoom || {})[roomId] || [])
      .map((msg) => msg.id)
      .filter((id) => typeof id === 'number')
      .reduce((min, id) => (min == null || id < min ? id : min), null);
    if (oldest == null || loadingOlder) return;
    setLoadingOlder(true);
    try {
      const page = await listChatMessages(roomId, { before_id: oldest, limit: 50 });
      const incoming = page.messages || [];
      if (!incoming.length) setHasOlder(false);
      poller?.mergeThread(roomId, incoming);
    } catch {
      setHasOlder(true);
    } finally {
      setLoadingOlder(false);
    }
  };

  const send = async () => {
    const body = text.trim();
    if (!body) return;
    const outbox = getChatOutbox();
    if (!outbox) return;
    setText('');
    try {
      await outbox.enqueue({ room_id: roomId, type: 'text', body });
    } catch {
      setText(body);
    }
  };

  if (!session?.poller) {
    return (
      <View style={styles.center}>
        <ActivityIndicator color="#fb923c" />
      </View>
    );
  }

  return (
    <KeyboardAvoidingView style={styles.wrap} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
      {pollerState.offline ? <Text style={styles.offline}>нет сети</Text> : null}
      <FlatList
        style={styles.list}
        inverted
        data={data}
        keyExtractor={(item) => String(item.id)}
        contentContainerStyle={{ padding: 12, paddingTop: 16 }}
        ListFooterComponent={hasOlder && rows.length ? (
          <Pressable accessibilityRole="button" style={styles.more} onPress={loadOlder} disabled={loadingOlder}>
            <Text style={styles.moreText}>{loadingOlder ? '…' : 'Ранее'}</Text>
          </Pressable>
        ) : null}
        ListEmptyComponent={<Text style={styles.muted}>Напишите первое сообщение</Text>}
        renderItem={({ item }) => {
          const mine = item.sender_id === userId && item.type !== 'system';
          const pending = item.local && item.status === 'pending';
          const failed = item.local && item.status === 'error';
          const conflict = failed && item.httpStatus === 409;
          return (
            <View style={[styles.bubble, item.type === 'system' ? styles.system : mine ? styles.mine : styles.theirs, pending && styles.pending]}>
              {item.type !== 'system' && !mine ? <Text style={styles.author}>{item.sender_name || ''}</Text> : null}
              <Text style={styles.body}>{bodyOf(item)}</Text>
              <Text style={styles.time}>{pending ? (item.error || 'отправляется') : formatTime(item.created_at)}</Text>
              {failed ? <Text style={styles.err}>{item.error || 'нет сети'}</Text> : null}
              {(pending && item.error) || (failed && !conflict) ? (
                <Pressable accessibilityRole="button" onPress={() => getChatOutbox()?.retry(item.client_id)}>
                  <Text style={styles.retry}>Повторить</Text>
                </Pressable>
              ) : null}
              {conflict ? (
                <Pressable accessibilityRole="button" onPress={() => getChatOutbox()?.drop(item.client_id)}>
                  <Text style={styles.retry}>Скрыть</Text>
                </Pressable>
              ) : null}
            </View>
          );
        }}
      />
      <View style={styles.composer}>
        <TextInput
          style={styles.input}
          placeholder="Сообщение"
          placeholderTextColor="#6b7280"
          value={text}
          onChangeText={setText}
          maxLength={4000}
          multiline
        />
        <Pressable accessibilityRole="button" style={styles.send} onPress={send} disabled={!text.trim()}>
          <Text style={styles.sendText}>Отправить</Text>
        </Pressable>
      </View>
    </KeyboardAvoidingView>
  );
}

const styles = StyleSheet.create({
  wrap: { flex: 1, backgroundColor: '#0b1018' },
  center: { flex: 1, backgroundColor: '#0b1018', justifyContent: 'center' },
  list: { flex: 1 },
  offline: { color: '#fca5a5', textAlign: 'center', paddingVertical: 8 },
  muted: { color: '#6b7280', textAlign: 'center', marginVertical: 24 },
  more: { alignSelf: 'center', paddingVertical: 8, paddingHorizontal: 14, marginBottom: 8 },
  moreText: { color: '#fb923c', fontWeight: '700' },
  bubble: { maxWidth: '82%', borderRadius: 14, padding: 10, marginBottom: 8 },
  mine: { alignSelf: 'flex-end', backgroundColor: '#3b2115' },
  theirs: { alignSelf: 'flex-start', backgroundColor: '#151b24' },
  system: { alignSelf: 'center', backgroundColor: 'transparent', maxWidth: '90%' },
  pending: { opacity: 0.7 },
  author: { color: '#fb923c', fontSize: 12, marginBottom: 4 },
  body: { color: '#fff', lineHeight: 20 },
  time: { color: '#6b7280', fontSize: 11, marginTop: 4 },
  err: { color: '#fca5a5', marginTop: 4 },
  retry: { color: '#fb923c', fontWeight: '700', marginTop: 4 },
  composer: { flexDirection: 'row', padding: 12, gap: 8, borderTopWidth: 1, borderTopColor: '#1f2937', alignItems: 'flex-end' },
  input: { flex: 1, backgroundColor: '#151b24', color: '#fff', borderRadius: 12, paddingHorizontal: 12, paddingVertical: 10, maxHeight: 120 },
  send: { backgroundColor: '#ea580c', borderRadius: 12, paddingHorizontal: 14, paddingVertical: 12, justifyContent: 'center' },
  sendText: { color: '#fff', fontWeight: '700' },
});
