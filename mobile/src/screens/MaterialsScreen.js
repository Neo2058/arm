import { useEffect, useState } from 'react';
import { ActivityIndicator, FlatList, Pressable, StyleSheet, Text, View } from 'react-native';
import { client } from '../api';

const TYPE_LABEL = {
  video: 'Видео',
  audio: 'Аудио',
  text: 'Текст',
  document: 'Документ',
};

export default function MaterialsScreen({ route, navigation }) {
  const { slug } = route.params;
  const [materials, setMaterials] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  useEffect(() => {
    (async () => {
      try {
        const { data } = await client.get(`/training/topics/${slug}`);
        setMaterials(data.materials || []);
      } catch {
        setError('Не удалось загрузить материалы');
      } finally {
        setLoading(false);
      }
    })();
  }, [slug]);

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
      data={materials}
      keyExtractor={(item) => String(item.id)}
      ListEmptyComponent={<Text style={styles.muted}>{error || 'Материалов нет'}</Text>}
      renderItem={({ item }) => (
        <Pressable
          style={styles.card}
          onPress={() => navigation.navigate('Material', { id: item.id, title: item.title })}
        >
          <Text style={styles.badge}>{TYPE_LABEL[item.type] || item.type}</Text>
          <Text style={styles.title}>{item.title}</Text>
          {item.duration ? <Text style={styles.meta}>{item.duration} сек</Text> : null}
        </Pressable>
      )}
    />
  );
}

const styles = StyleSheet.create({
  list: { flex: 1, backgroundColor: '#0b1018' },
  center: { flex: 1, backgroundColor: '#0b1018', justifyContent: 'center' },
  card: { backgroundColor: '#151b24', borderRadius: 16, padding: 16, marginBottom: 12 },
  badge: { color: '#fb923c', fontSize: 12, fontWeight: '700', marginBottom: 6 },
  title: { color: '#fff', fontSize: 17, fontWeight: '600' },
  meta: { color: '#6b7280', marginTop: 6 },
  muted: { color: '#9ca3af', textAlign: 'center', marginTop: 40 },
});
