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

export default function TopicsScreen({ navigation }) {
  const [topics, setTopics] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  const load = async () => {
    setError('');
    try {
      const { data } = await client.get('/training/topics');
      setTopics(data.topics || []);
    } catch {
      setError('Не удалось загрузить темы');
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
      contentContainerStyle={{ padding: 16 }}
      data={topics}
      keyExtractor={(item) => String(item.id)}
      refreshControl={<RefreshControl refreshing={false} onRefresh={load} />}
      ListEmptyComponent={<Text style={styles.muted}>{error || 'Тем пока нет'}</Text>}
      renderItem={({ item }) => (
        <Pressable
          style={styles.card}
          onPress={() => navigation.navigate('Materials', { slug: item.slug, title: item.title })}
        >
          <Text style={styles.title}>{item.title}</Text>
          {item.description ? <Text style={styles.desc}>{item.description}</Text> : null}
        </Pressable>
      )}
    />
  );
}

const styles = StyleSheet.create({
  list: { flex: 1, backgroundColor: '#0b1018' },
  center: { flex: 1, backgroundColor: '#0b1018', justifyContent: 'center' },
  card: { backgroundColor: '#151b24', borderRadius: 16, padding: 16, marginBottom: 12 },
  title: { color: '#fff', fontSize: 18, fontWeight: '600' },
  desc: { color: '#9ca3af', marginTop: 6 },
  muted: { color: '#9ca3af', textAlign: 'center', marginTop: 40 },
});
