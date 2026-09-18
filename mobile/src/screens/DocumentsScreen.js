import { useCallback, useState } from 'react';
import {
  ActivityIndicator,
  FlatList,
  Pressable,
  RefreshControl,
  StyleSheet,
  Text,
  View,
} from 'react-native';
import { useFocusEffect } from '@react-navigation/native';
import { client } from '../api';

export default function DocumentsScreen({ navigation }) {
  const [categories, setCategories] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  const load = async () => {
    setError('');
    try {
      const { data } = await client.get('/documents');
      setCategories(data.categories || []);
    } catch {
      setError('Не удалось загрузить документы');
    } finally {
      setLoading(false);
    }
  };

  useFocusEffect(
    useCallback(() => {
      load();
    }, [])
  );

  if (loading) {
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
      data={categories}
      keyExtractor={(item) => item.name}
      refreshControl={<RefreshControl refreshing={false} onRefresh={load} />}
      ListEmptyComponent={<Text style={styles.muted}>{error || 'Документов нет'}</Text>}
      renderItem={({ item }) => (
        <View style={styles.card}>
          <Text style={styles.cat}>{item.name}</Text>
          {item.items.map((doc) => (
            <Pressable
              key={doc.id}
              style={styles.row}
              onPress={() => navigation.navigate('DocumentView', { id: doc.id, title: doc.title })}
            >
              <Text style={styles.doc}>{doc.title}</Text>
              <Text style={styles.date}>{doc.updated_at}</Text>
            </Pressable>
          ))}
        </View>
      )}
    />
  );
}

const styles = StyleSheet.create({
  list: { flex: 1, backgroundColor: '#0b1018' },
  center: { flex: 1, backgroundColor: '#0b1018', justifyContent: 'center' },
  card: { backgroundColor: '#151b24', borderRadius: 16, padding: 14, marginBottom: 12 },
  cat: { color: '#fb923c', fontWeight: '700', marginBottom: 8 },
  row: { paddingVertical: 10, borderTopWidth: StyleSheet.hairlineWidth, borderTopColor: '#1f2937' },
  doc: { color: '#fff', fontSize: 16 },
  date: { color: '#6b7280', marginTop: 4, fontSize: 12 },
  muted: { color: '#9ca3af', textAlign: 'center', marginTop: 40 },
});
