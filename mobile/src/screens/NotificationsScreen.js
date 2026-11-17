import { useCallback, useState } from 'react';
import { ActivityIndicator, Pressable, RefreshControl, ScrollView, StyleSheet, Text, View } from 'react-native';
import { useFocusEffect } from '@react-navigation/native';
import { listNotifications, markNotificationRead, markNotificationsRead } from '../api';
import AppIcon, { IconHeading } from '../components/AppIcon';

function openTarget(navigation, item) {
  if (!item) return;
  if (item.type === 'chat_message') {
    navigation.getParent()?.navigate('ChatTab', item.resource_id ? { screen: 'ChatThread', params: { id: item.resource_id, title: item.title }, initial: false } : undefined);
    return;
  }
  if (item.type === 'formular_entry' || item.type === 'formular_review') {
    navigation.navigate('Formular');
    return;
  }
  if (item.type === 'instructor_notice') {
    const parent = navigation.getParent();
    if (parent?.getState?.()?.routeNames?.includes('DisciplineTab')) {
      parent.navigate('DisciplineTab');
    }
  }
}

export default function NotificationsScreen({ navigation }) {
  const [items, setItems] = useState([]);
  const [unread, setUnread] = useState(0);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);

  const refresh = useCallback(async () => {
    setBusy(true);
    try {
      const data = await listNotifications({ unread: false });
      setItems(data.items || []);
      setUnread(Number(data.unread) || 0);
      setError('');
    } catch {
      setError('Не удалось загрузить уведомления. Проверьте соединение.');
    } finally {
      setLoading(false);
      setBusy(false);
    }
  }, []);

  useFocusEffect(useCallback(() => {
    refresh();
  }, [refresh]));

  const markOne = async (item) => {
    try {
      if (!item.read_at) await markNotificationRead(item.id);
    } catch {
      setError('Не удалось отметить уведомление.');
      return;
    }
    openTarget(navigation, item);
    refresh();
  };

  return (
    <ScrollView style={s.page} contentContainerStyle={s.content} refreshControl={<RefreshControl refreshing={busy && !loading} onRefresh={refresh} tintColor="#fb923c" />}>
      <IconHeading name="bell">Уведомления</IconHeading>
      <Text style={s.meta}>Непрочитанных: {unread}</Text>
      {!!error && <Text accessibilityRole="alert" style={s.error}>{error}</Text>}
      <Pressable style={s.secondary} onPress={async () => { try { await markNotificationsRead(); await refresh(); } catch { setError('Не удалось отметить все.'); } }}>
        <Text style={s.secondaryText}>Отметить все прочитанными</Text>
      </Pressable>
      {loading ? <ActivityIndicator color="#fb923c" /> : items.length === 0 ? (
        <Text style={s.meta}>Пока нет уведомлений.</Text>
      ) : items.map((item) => (
        <Pressable key={item.id} style={[s.card, item.read_at ? s.read : null]} onPress={() => markOne(item)}>
          <View style={s.row}>
            <AppIcon name="bell" size={18} />
            <Text style={s.title}>{item.title}</Text>
          </View>
          <Text style={s.meta}>{item.created_at}</Text>
          {item.type === 'chat_message' ? <Text style={s.meta}>Откройте чат, чтобы прочитать сообщение.</Text> : null}
        </Pressable>
      ))}
    </ScrollView>
  );
}

const s = StyleSheet.create({
  page: { flex: 1, backgroundColor: '#0b1018' },
  content: { padding: 24, paddingBottom: 48, gap: 12 },
  meta: { color: '#9ca3af', fontSize: 14, lineHeight: 21 },
  error: { color: '#fca5a5' },
  secondary: { minHeight: 44, padding: 12, borderRadius: 12, backgroundColor: '#253041', justifyContent: 'center' },
  secondaryText: { color: '#fb923c', fontWeight: '700' },
  card: { backgroundColor: '#151b24', borderColor: '#283241', borderWidth: 1, borderRadius: 16, padding: 16, gap: 8 },
  read: { opacity: 0.55 },
  row: { flexDirection: 'row', alignItems: 'center', gap: 10 },
  title: { color: '#fff', fontSize: 16, fontWeight: '600', flex: 1 },
});
