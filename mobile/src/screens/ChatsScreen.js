import { useEffect, useState } from 'react';
import {
  ActivityIndicator,
  FlatList,
  Pressable,
  RefreshControl,
  StyleSheet,
  Text,
  View,
} from 'react-native';
import { getChatPoller, whenChatSession } from '../chatPoller';

const TYPE_LABEL = { column: 'Колонна', leadership: 'Руководители', work: 'Рабочий' };

function formatTime(iso) {
  if (!iso) return '';
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return '';
  const pad = (n) => String(n).padStart(2, '0');
  return `${pad(date.getDate())}.${pad(date.getMonth() + 1)} ${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

export default function ChatsScreen({ navigation }) {
  const [session, setSession] = useState(() => null);
  const [state, setState] = useState({ rooms: [], offline: false });
  const [busy, setBusy] = useState(false);

  useEffect(() => whenChatSession((next) => {
    setSession(next);
    if (!next?.poller) return;
    setState(next.poller.getState());
  }), []);

  useEffect(() => {
    if (!session?.poller) return undefined;
    return session.poller.subscribe(setState);
  }, [session]);

  const refresh = async () => {
    const poller = getChatPoller();
    if (!poller) return;
    setBusy(true);
    try { await poller.syncNow(); } finally { setBusy(false); }
  };

  if (!session?.poller) {
    return (
      <View style={styles.center}>
        <ActivityIndicator color="#fb923c" />
      </View>
    );
  }

  return (
    <FlatList
      style={styles.list}
      contentContainerStyle={{ padding: 16, paddingBottom: 40 }}
      data={state.rooms || []}
      keyExtractor={(item) => String(item.id)}
      refreshControl={<RefreshControl tintColor="#fb923c" refreshing={busy} onRefresh={refresh} />}
      ListHeaderComponent={state.offline ? <Text style={styles.offline}>нет сети</Text> : null}
      ListEmptyComponent={<Text style={styles.muted}>{state.offline ? 'нет сети' : 'Чатов пока нет'}</Text>}
      renderItem={({ item }) => (
        <Pressable
          accessibilityRole="button"
          style={styles.card}
          onPress={() => navigation.navigate('ChatThread', {
            id: item.id,
            title: item.title,
          })}
        >
          <View style={styles.row}>
            <Text style={styles.title} numberOfLines={1}>{item.title}</Text>
            {item.unread > 0 ? <Text style={styles.badge}>{item.unread}</Text> : null}
          </View>
          <Text style={styles.meta}>{TYPE_LABEL[item.type] || item.type}{item.column ? ` · ${item.column}` : ''}</Text>
          <Text style={styles.preview} numberOfLines={2}>
            {item.last_message
              ? `${item.last_message.sender_name ? `${item.last_message.sender_name}: ` : ''}${item.last_message.preview}`
              : 'Нет сообщений'}
          </Text>
          {item.last_message?.created_at ? <Text style={styles.time}>{formatTime(item.last_message.created_at)}</Text> : null}
        </Pressable>
      )}
    />
  );
}

const styles = StyleSheet.create({
  list: { flex: 1, backgroundColor: '#0b1018' },
  center: { flex: 1, backgroundColor: '#0b1018', justifyContent: 'center' },
  card: { backgroundColor: '#151b24', borderRadius: 16, padding: 16, marginBottom: 12 },
  row: { flexDirection: 'row', alignItems: 'center', gap: 8 },
  title: { color: '#fff', fontSize: 17, fontWeight: '700', flex: 1 },
  badge: { backgroundColor: '#ea580c', color: '#fff', overflow: 'hidden', minWidth: 22, textAlign: 'center', paddingHorizontal: 7, paddingVertical: 2, borderRadius: 11, fontWeight: '700', fontSize: 12 },
  meta: { color: '#fb923c', marginTop: 6, fontSize: 12, fontWeight: '600' },
  preview: { color: '#9ca3af', marginTop: 6, lineHeight: 20 },
  time: { color: '#6b7280', marginTop: 6, fontSize: 12 },
  muted: { color: '#9ca3af', textAlign: 'center', marginTop: 40 },
  offline: { color: '#fca5a5', textAlign: 'center', marginBottom: 12 },
});
