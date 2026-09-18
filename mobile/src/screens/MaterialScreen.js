import { useEffect, useState } from 'react';
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
import { Audio, Video, ResizeMode } from 'expo-av';
import { client } from '../api';

export default function MaterialScreen({ route }) {
  const { id } = route.params;
  const [material, setMaterial] = useState(null);
  const [error, setError] = useState('');
  const [comment, setComment] = useState('');
  const [sending, setSending] = useState(false);

  const load = async () => {
    try {
      const { data } = await client.get(`/training/materials/${id}`);
      setMaterial(data);
    } catch {
      setError('Материал недоступен');
    }
  };

  useEffect(() => {
    load();
  }, [id]);

  useEffect(() => {
    Audio.setAudioModeAsync({ playsInSilentModeIOS: true }).catch(() => {});
  }, []);

  const send = async () => {
    const body = comment.trim();
    if (!body) return;
    setSending(true);
    try {
      const { data } = await client.post(`/training/materials/${id}/comments`, { comment: body });
      setMaterial((prev) => ({
        ...prev,
        comments: [data, ...(prev?.comments || [])],
      }));
      setComment('');
    } catch {
      setError('Не удалось отправить комментарий');
    } finally {
      setSending(false);
    }
  };

  if (!material && !error) {
    return (
      <View style={styles.center}>
        <ActivityIndicator color="#fb923c" />
      </View>
    );
  }

  if (error && !material) {
    return (
      <View style={styles.center}>
        <Text style={styles.err}>{error}</Text>
      </View>
    );
  }

  return (
    <KeyboardAvoidingView style={styles.wrap} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
      <FlatList
        data={material.comments}
        keyExtractor={(item) => String(item.id)}
        contentContainerStyle={{ padding: 16, paddingBottom: 24 }}
        ListHeaderComponent={
          <View>
            <Text style={styles.title}>{material.title}</Text>
            {material.description ? <Text style={styles.desc}>{material.description}</Text> : null}
            {material.type === 'video' && material.url ? (
              <Video
                source={{ uri: material.url }}
                style={styles.video}
                useNativeControls
                resizeMode={ResizeMode.CONTAIN}
              />
            ) : null}
            {material.type === 'audio' && material.url ? (
              <Video
                source={{ uri: material.url }}
                style={styles.audio}
                useNativeControls
                resizeMode={ResizeMode.CONTAIN}
              />
            ) : null}
            {material.type === 'text' && material.content ? (
              <Text style={styles.body}>{material.content}</Text>
            ) : null}
            <Text style={styles.section}>Комментарии</Text>
            {error ? <Text style={styles.err}>{error}</Text> : null}
          </View>
        }
        ListEmptyComponent={<Text style={styles.muted}>Пока нет комментариев</Text>}
        renderItem={({ item }) => (
          <View style={styles.comment}>
            <Text style={styles.author}>{item.author || 'Сотрудник'}</Text>
            <Text style={styles.commentBody}>{item.body}</Text>
          </View>
        )}
      />
      <View style={styles.composer}>
        <TextInput
          style={styles.input}
          placeholder="Ваш комментарий"
          placeholderTextColor="#6b7280"
          value={comment}
          onChangeText={setComment}
        />
        <Pressable style={styles.send} onPress={send} disabled={sending}>
          <Text style={styles.sendText}>{sending ? '…' : 'Отправить'}</Text>
        </Pressable>
      </View>
    </KeyboardAvoidingView>
  );
}

const styles = StyleSheet.create({
  wrap: { flex: 1, backgroundColor: '#0b1018' },
  center: { flex: 1, backgroundColor: '#0b1018', justifyContent: 'center', alignItems: 'center' },
  title: { color: '#fff', fontSize: 22, fontWeight: '700', marginBottom: 8 },
  desc: { color: '#9ca3af', marginBottom: 12 },
  video: { width: '100%', height: 220, backgroundColor: '#000', borderRadius: 12, marginBottom: 16 },
  audio: { width: '100%', height: 72, backgroundColor: '#111', borderRadius: 12, marginBottom: 16 },
  body: { color: '#e5e7eb', lineHeight: 22, marginBottom: 16 },
  section: { color: '#fb923c', fontWeight: '700', marginBottom: 10, marginTop: 8 },
  comment: { backgroundColor: '#151b24', borderRadius: 12, padding: 12, marginBottom: 8 },
  author: { color: '#fb923c', fontSize: 12, marginBottom: 4 },
  commentBody: { color: '#fff' },
  muted: { color: '#6b7280', marginBottom: 12 },
  err: { color: '#f87171', marginBottom: 8 },
  composer: { flexDirection: 'row', padding: 12, gap: 8, borderTopWidth: 1, borderTopColor: '#1f2937' },
  input: { flex: 1, backgroundColor: '#151b24', color: '#fff', borderRadius: 12, paddingHorizontal: 12, paddingVertical: 10 },
  send: { backgroundColor: '#ea580c', borderRadius: 12, paddingHorizontal: 14, justifyContent: 'center' },
  sendText: { color: '#fff', fontWeight: '700' },
});
