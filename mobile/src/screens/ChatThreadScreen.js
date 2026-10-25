import { useCallback, useEffect, useLayoutEffect, useMemo, useState } from 'react';
import {
  ActivityIndicator,
  Alert,
  FlatList,
  Image,
  KeyboardAvoidingView,
  Platform,
  Pressable,
  StyleSheet,
  Text,
  TextInput,
  View,
} from 'react-native';
import { useFocusEffect } from '@react-navigation/native';
import * as Crypto from 'expo-crypto';
import * as DocumentPicker from 'expo-document-picker';
import * as FileSystem from 'expo-file-system/legacy';
import * as ImageManipulator from 'expo-image-manipulator';
import * as ImagePicker from 'expo-image-picker';
import { listChatMessages } from '../api';
import { canManageMembers, getChatOutbox, getChatPoller, whenChatSession } from '../chatPoller';

const MAX_FILE_BYTES = 10 * 1024 * 1024;
const MAX_SIDE = 2048;

function formatTime(iso) {
  if (!iso) return '';
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return '';
  const pad = (n) => String(n).padStart(2, '0');
  return `${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

function bodyOf(item) {
  if (item.status === 'rejected') return 'отклонено';
  if (item.type === 'deleted') return 'Сообщение удалено';
  if (item.type === 'image') return item.body || 'Фото';
  if (item.type === 'pdf') return item.body || (item.file?.name || 'PDF');
  if (item.type === 'system') return item.body || 'Системное';
  return item.body || '';
}

function threadRows(roomId, pollerState, outboxItems, userId) {
  const server = (pollerState.messagesByRoom && pollerState.messagesByRoom[roomId]) || [];
  const seen = new Set(server.map((msg) => msg.client_id).filter(Boolean));
  const local = (outboxItems || [])
    .filter((item) => item.room_id === roomId && !seen.has(item.client_id))
    .map((item) => ({
      id: `local-${item.client_id}`,
      local: true,
      client_id: item.client_id,
      type: item.type,
      body: item.body,
      localUri: item.localUri,
      sender_id: userId,
      status: item.status,
      error: item.error,
      httpStatus: item.httpStatus,
      created_at: item.created_at,
    }));
  return [...server, ...local];
}

function bufferToHex(buffer) {
  return Array.from(new Uint8Array(buffer), (b) => b.toString(16).padStart(2, '0')).join('');
}

async function sha256File(uri) {
  const base64 = await FileSystem.readAsStringAsync(uri, { encoding: FileSystem.EncodingType.Base64 });
  const binary = globalThis.atob(base64);
  const bytes = new Uint8Array(binary.length);
  for (let i = 0; i < binary.length; i += 1) bytes[i] = binary.charCodeAt(i);
  const digest = await Crypto.digest(Crypto.CryptoDigestAlgorithm.SHA256, bytes);
  return bufferToHex(digest);
}

async function fileSize(uri) {
  const info = await FileSystem.getInfoAsync(uri);
  return info.exists ? info.size : 0;
}

function resizeActions(width, height) {
  const w = width || 0;
  const h = height || 0;
  const longest = Math.max(w, h);
  if (longest <= MAX_SIDE || longest === 0) return [];
  if (w >= h) return [{ resize: { width: MAX_SIDE } }];
  return [{ resize: { height: MAX_SIDE } }];
}

export default function ChatThreadScreen({ route, navigation }) {
  const roomId = route.params.id;
  const [session, setSession] = useState(null);
  const [pollerState, setPollerState] = useState({ messagesByRoom: {}, rooms: [], offline: false, userId: null });
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
  const live = (pollerState.rooms || []).find((row) => row.id === roomId);
  const canManage = live
    ? canManageMembers(live)
    : route.params?.can_manage_members === true;

  useLayoutEffect(() => {
    navigation.setOptions({
      headerRight: () => (
        <Pressable
          accessibilityRole="button"
          onPress={() => navigation.navigate('ChatMembers', {
            id: roomId,
            can_manage_members: canManage,
          })}
          style={{ marginRight: 8 }}
        >
          <Text style={{ color: '#fb923c', fontWeight: '700' }}>Участники</Text>
        </Pressable>
      ),
    });
  }, [navigation, roomId, canManage]);

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
      await poller?.mergeThread(roomId, incoming);
    } catch {
      setHasOlder(true);
    } finally {
      setLoadingOlder(false);
    }
  };

  const sendText = async () => {
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

  const enqueueFile = async ({ type, uri, name, mime, caption }) => {
    const outbox = getChatOutbox();
    if (!outbox) return false;
    const size = await fileSize(uri);
    if (!size || size > MAX_FILE_BYTES) {
      Alert.alert('Файл', 'Допустимы файлы размером от 1 байта до 10 МиБ.');
      return false;
    }
    const fileSha256 = await sha256File(uri);
    await outbox.enqueue({
      room_id: roomId,
      type,
      body: caption,
      localUri: uri,
      fileName: name,
      mime,
      fileSha256,
    });
    return true;
  };

  const pickPhoto = async () => {
    try {
      const permission = await ImagePicker.requestMediaLibraryPermissionsAsync();
      if (!permission.granted) {
        Alert.alert('Фото', 'Нужен доступ к снимкам, чтобы отправить фото в чат.');
        return;
      }
      const picked = await ImagePicker.launchImageLibraryAsync({
        mediaTypes: ['images'],
        quality: 1,
        allowsEditing: false,
      });
      if (picked.canceled || !picked.assets?.[0]) return;
      const asset = picked.assets[0];
      const prepared = await ImageManipulator.manipulateAsync(
        asset.uri,
        resizeActions(asset.width, asset.height),
        { compress: 0.7, format: ImageManipulator.SaveFormat.JPEG },
      );
      const caption = text.trim().slice(0, 500);
      setText('');
      try {
        const ok = await enqueueFile({
          type: 'image',
          uri: prepared.uri,
          name: 'photo.jpg',
          mime: 'image/jpeg',
          caption,
        });
        if (!ok) setText(caption);
      } catch {
        setText(caption);
      }
    } catch {
      Alert.alert('Фото', 'Не удалось прочитать снимок. Повторите выбор.');
    }
  };

  const pickPdf = async () => {
    try {
      const picked = await DocumentPicker.getDocumentAsync({
        type: 'application/pdf',
        copyToCacheDirectory: true,
        multiple: false,
      });
      if (picked.canceled || !picked.assets?.[0]) return;
      const asset = picked.assets[0];
      const name = asset.name || 'file.pdf';
      if (!name.toLowerCase().endsWith('.pdf')) {
        Alert.alert('PDF', 'Допустимы только PDF, JPEG и PNG с соответствующим расширением.');
        return;
      }
      const caption = text.trim().slice(0, 500);
      setText('');
      try {
        const ok = await enqueueFile({
          type: 'pdf',
          uri: asset.uri,
          name,
          mime: asset.mimeType || 'application/pdf',
          caption,
        });
        if (!ok) setText(caption);
      } catch {
        setText(caption);
      }
    } catch {
      Alert.alert('PDF', 'Не удалось прочитать выбранный файл. Повторите выбор.');
    }
  };

  const openPdf = (item) => {
    const href = item.file?.url;
    if (!href) return;
    navigation.navigate('ChatPdf', { url: href, title: item.file?.name || 'PDF' });
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
          const mine = item.status === 'rejected' || (item.sender_id === userId && item.type !== 'system');
          const pending = item.local && item.status === 'pending';
          const queued = item.local && item.status === 'queued';
          const failed = item.local && item.status === 'error';
          const conflict = failed && item.httpStatus === 409;
          const photoUri = item.type === 'image' ? (item.file?.url || item.localUri) : null;
          return (
            <View style={[styles.bubble, item.type === 'system' ? styles.system : mine ? styles.mine : styles.theirs, (pending || queued) && styles.pending]}>
              {item.type !== 'system' && !mine ? <Text style={styles.author}>{item.sender_name || ''}</Text> : null}
              {photoUri && item.status !== 'rejected' ? (
                <Image source={{ uri: photoUri }} style={styles.photo} resizeMode="cover" />
              ) : null}
              {item.type === 'pdf' && item.status !== 'rejected' && item.file?.url ? (
                <Pressable accessibilityRole="button" onPress={() => openPdf(item)}>
                  <Text style={styles.link}>Открыть PDF</Text>
                </Pressable>
              ) : null}
              <Text style={styles.body}>{bodyOf(item)}</Text>
              <Text style={styles.time}>
                {item.status === 'rejected'
                  ? 'отклонено'
                  : queued
                    ? 'на проверке'
                    : pending
                      ? (item.error || 'отправляется')
                      : formatTime(item.created_at)}
              </Text>
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
      <View style={styles.attachRow}>
        <Pressable accessibilityRole="button" style={styles.attach} onPress={pickPhoto}>
          <Text style={styles.attachText}>Фото</Text>
        </Pressable>
        <Pressable accessibilityRole="button" style={styles.attach} onPress={pickPdf}>
          <Text style={styles.attachText}>PDF</Text>
        </Pressable>
      </View>
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
        <Pressable accessibilityRole="button" style={styles.send} onPress={sendText} disabled={!text.trim()}>
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
  photo: { width: 220, height: 220, borderRadius: 8, marginBottom: 6, backgroundColor: '#0b1018' },
  link: { color: '#fb923c', fontWeight: '700', marginBottom: 6 },
  time: { color: '#6b7280', fontSize: 11, marginTop: 4 },
  err: { color: '#fca5a5', marginTop: 4 },
  retry: { color: '#fb923c', fontWeight: '700', marginTop: 4 },
  attachRow: { flexDirection: 'row', gap: 8, paddingHorizontal: 12, paddingTop: 8 },
  attach: { backgroundColor: '#151b24', borderRadius: 12, paddingHorizontal: 14, paddingVertical: 8 },
  attachText: { color: '#fb923c', fontWeight: '700' },
  composer: { flexDirection: 'row', padding: 12, gap: 8, borderTopWidth: 1, borderTopColor: '#1f2937', alignItems: 'flex-end' },
  input: { flex: 1, backgroundColor: '#151b24', color: '#fff', borderRadius: 12, paddingHorizontal: 12, paddingVertical: 10, maxHeight: 120 },
  send: { backgroundColor: '#ea580c', borderRadius: 12, paddingHorizontal: 14, paddingVertical: 12, justifyContent: 'center' },
  sendText: { color: '#fff', fontWeight: '700' },
});
