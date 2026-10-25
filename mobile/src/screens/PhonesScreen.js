import { useCallback, useEffect, useState } from 'react';
import {
  ActivityIndicator,
  FlatList,
  Linking,
  Pressable,
  RefreshControl,
  StyleSheet,
  Text,
  TextInput,
  View,
} from 'react-native';
import { client } from '../api';
import { IconLabel } from '../components/AppIcon';

export default function PhonesScreen() {
  const [phones, setPhones] = useState([]);
  const [query, setQuery] = useState('');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  const load = useCallback(async (q) => {
    setError('');
    try {
      const { data } = await client.get('/phones', { params: q ? { q } : {} });
      setPhones(data.phones || []);
    } catch {
      setError('Не удалось загрузить телефоны');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    const wait = query.trim() ? 300 : 0;
    const handle = setTimeout(() => {
      load(query.trim());
    }, wait);
    return () => clearTimeout(handle);
  }, [query, load]);

  const call = (number) => {
    if (!number) return;
    const tel = number.replace(/[^\d+]/g, '');
    Linking.openURL(`tel:${tel}`).catch(() => {});
  };

  return (
    <View style={styles.wrap}>
      <TextInput
        style={styles.search}
        placeholder="Поиск по ФИО или номеру"
        placeholderTextColor="#6b7280"
        value={query}
        onChangeText={setQuery}
        autoCorrect={false}
      />
      {loading && phones.length === 0 ? (
        <ActivityIndicator color="#fb923c" style={{ marginTop: 24 }} />
      ) : (
        <FlatList
          data={phones}
          keyExtractor={(item) => String(item.id)}
          contentContainerStyle={{ padding: 16, paddingBottom: 40 }}
          refreshControl={<RefreshControl refreshing={false} onRefresh={() => load(query.trim())} />}
          ListEmptyComponent={<Text style={styles.muted}>{error || 'Номеров нет'}</Text>}
          renderItem={({ item }) => (
            <View style={styles.card}>
              <Text style={styles.name}>{item.name}</Text>
              {item.position ? <Text style={styles.meta}>{item.position}</Text> : null}
              {item.phone ? (
                <Pressable onPress={() => call(item.phone)}>
                  <IconLabel name="phone" color="#fb923c" textStyle={styles.phone} style={{ justifyContent: 'flex-start', minHeight: 44 }}>{item.phone}</IconLabel>
                </Pressable>
              ) : null}
              {item.phone2 ? (
                <Pressable onPress={() => call(item.phone2)}>
                  <IconLabel name="phone" color="#fb923c" textStyle={styles.phone} style={{ justifyContent: 'flex-start', minHeight: 44 }}>{item.phone2}</IconLabel>
                </Pressable>
              ) : null}
            </View>
          )}
        />
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  wrap: { flex: 1, backgroundColor: '#0b1018' },
  search: {
    margin: 16,
    marginBottom: 0,
    backgroundColor: '#151b24',
    color: '#fff',
    borderRadius: 12,
    paddingHorizontal: 14,
    paddingVertical: 12,
  },
  card: { backgroundColor: '#151b24', borderRadius: 16, padding: 16, marginBottom: 12 },
  name: { color: '#fff', fontSize: 16, fontWeight: '600' },
  meta: { color: '#6b7280', marginTop: 4 },
  phone: { color: '#fb923c', marginTop: 8, fontSize: 16 },
  muted: { color: '#9ca3af', textAlign: 'center', marginTop: 40 },
});
