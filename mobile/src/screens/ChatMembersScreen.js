import { useCallback, useEffect, useState } from 'react';
import {
  ActivityIndicator,
  Alert,
  FlatList,
  Pressable,
  StyleSheet,
  Text,
  TextInput,
  View,
} from 'react-native';
import { useFocusEffect } from '@react-navigation/native';
import {
  addChatMember,
  listChatMembers,
  listEligibleChatMembers,
  removeChatMember,
} from '../api';
import { canManageMembers, getChatPoller, whenChatSession } from '../chatPoller';

const ROLE_LABEL = { moderator: 'модератор', member: 'участник' };
const SOURCE_LABEL = { deputy: 'заместитель', regular: '' };

function memberMeta(item) {
  const parts = [ROLE_LABEL[item.role] || item.role, SOURCE_LABEL[item.source] || ''].filter(Boolean);
  return parts.join(' · ');
}

function apiError(error) {
  const data = error && error.response && error.response.data;
  const fromFields = Object.values((data && data.errors) || {}).flat().find((row) => typeof row === 'string');
  return fromFields || (data && data.message) || 'Не удалось изменить состав чата.';
}

export default function ChatMembersScreen({ route }) {
  const roomId = route.params.id;
  const [session, setSession] = useState(null);
  const [pollerState, setPollerState] = useState({ rooms: [], userId: null });
  const [members, setMembers] = useState([]);
  const [eligible, setEligible] = useState([]);
  const [query, setQuery] = useState('');
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  const [eligibleTick, setEligibleTick] = useState(0);

  useEffect(() => whenChatSession(setSession), []);

  useEffect(() => {
    if (!session?.poller) return undefined;
    return session.poller.subscribe(setPollerState);
  }, [session]);

  const live = (pollerState.rooms || []).find((row) => row.id === roomId);
  const canManage = live
    ? canManageMembers(live)
    : route.params?.can_manage_members === true;
  const userId = pollerState.userId;

  const reloadMembers = useCallback(async () => {
    const rows = await listChatMembers(roomId);
    setMembers(rows);
  }, [roomId]);

  useFocusEffect(useCallback(() => {
    let cancelled = false;
    (async () => {
      try {
        const rows = await listChatMembers(roomId);
        if (!cancelled) {
          setMembers(rows);
          setError('');
        }
      } catch {
        if (!cancelled) setError('нет сети');
      }
    })();
    return () => { cancelled = true; };
  }, [roomId]));

  useEffect(() => {
    if (!canManage) {
      setEligible([]);
      return undefined;
    }
    let cancelled = false;
    const timer = setTimeout(async () => {
      try {
        const users = await listEligibleChatMembers(roomId, query.trim());
        if (!cancelled) setEligible(users);
      } catch {
        if (!cancelled) setEligible([]);
      }
    }, 250);
    return () => {
      cancelled = true;
      clearTimeout(timer);
    };
  }, [canManage, roomId, query, eligibleTick]);

  const add = async (user) => {
    if (busy) return;
    setBusy(true);
    try {
      await addChatMember(roomId, user.id);
      await reloadMembers();
      setEligibleTick((n) => n + 1);
      getChatPoller()?.syncNow();
    } catch (err) {
      Alert.alert('Участники', apiError(err));
    } finally {
      setBusy(false);
    }
  };

  const remove = (user) => {
    if (busy) return;
    Alert.alert('Удалить', user.name, [
      { text: 'Отмена', style: 'cancel' },
      {
        text: 'Удалить',
        style: 'destructive',
        onPress: async () => {
          setBusy(true);
          try {
            await removeChatMember(roomId, user.id);
            await reloadMembers();
            setEligibleTick((n) => n + 1);
            getChatPoller()?.syncNow();
          } catch (err) {
            Alert.alert('Участники', apiError(err));
          } finally {
            setBusy(false);
          }
        },
      },
    ]);
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
      data={members}
      keyExtractor={(item) => String(item.id)}
      ListHeaderComponent={(
        <View>
          {error ? <Text style={styles.offline}>{error}</Text> : null}
          {canManage ? (
            <View style={styles.manage}>
              <Text style={styles.section}>Добавить</Text>
              <TextInput
                style={styles.input}
                placeholder="Поиск по имени"
                placeholderTextColor="#6b7280"
                value={query}
                onChangeText={setQuery}
                autoCorrect={false}
              />
              {eligible.map((user) => (
                <Pressable
                  key={user.id}
                  accessibilityRole="button"
                  style={styles.eligible}
                  onPress={() => add(user)}
                  disabled={busy}
                >
                  <Text style={styles.name}>{user.name}</Text>
                  <Text style={styles.add}>Добавить</Text>
                </Pressable>
              ))}
              {eligible.length === 0 ? <Text style={styles.muted}>Никого не найдено</Text> : null}
            </View>
          ) : null}
          <Text style={styles.section}>В чате</Text>
        </View>
      )}
      ListEmptyComponent={<Text style={styles.muted}>Нет участников</Text>}
      renderItem={({ item }) => {
        const canRemove = canManage && item.id !== userId && item.source !== 'deputy';
        return (
          <View style={styles.card}>
            <View style={styles.row}>
              <Text style={styles.name}>{item.name}</Text>
              {canRemove ? (
                <Pressable accessibilityRole="button" onPress={() => remove(item)} disabled={busy}>
                  <Text style={styles.remove}>Удалить</Text>
                </Pressable>
              ) : null}
            </View>
            <Text style={styles.meta}>{memberMeta(item)}</Text>
          </View>
        );
      }}
    />
  );
}

const styles = StyleSheet.create({
  list: { flex: 1, backgroundColor: '#0b1018' },
  center: { flex: 1, backgroundColor: '#0b1018', justifyContent: 'center' },
  manage: { marginBottom: 16 },
  section: { color: '#fb923c', fontWeight: '700', marginBottom: 8 },
  input: { backgroundColor: '#151b24', color: '#fff', borderRadius: 12, paddingHorizontal: 12, paddingVertical: 10, marginBottom: 8 },
  eligible: { flexDirection: 'row', alignItems: 'center', backgroundColor: '#151b24', borderRadius: 12, padding: 12, marginBottom: 8 },
  add: { color: '#fb923c', fontWeight: '700' },
  card: { backgroundColor: '#151b24', borderRadius: 16, padding: 16, marginBottom: 12 },
  row: { flexDirection: 'row', alignItems: 'center', gap: 8 },
  name: { color: '#fff', fontSize: 16, fontWeight: '600', flex: 1 },
  meta: { color: '#9ca3af', marginTop: 6, fontSize: 12 },
  remove: { color: '#fca5a5', fontWeight: '700' },
  muted: { color: '#6b7280', marginBottom: 12 },
  offline: { color: '#fca5a5', textAlign: 'center', marginBottom: 12 },
});
