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
import { IconHeading } from '../components/AppIcon';

export default function NaryadsScreen({ navigation }) {
  const [naryads, setNaryads] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  const load = async () => {
    setError('');
    try {
      const { data } = await client.get('/naryads');
      setNaryads(data.naryads || []);
    } catch {
      setError('Не удалось загрузить наряды');
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
      data={naryads}
      keyExtractor={(item) => String(item.id)}
      refreshControl={<RefreshControl refreshing={false} onRefresh={load} />}
      ListEmptyComponent={<Text style={styles.muted}>{error || 'Выданных нарядов нет'}</Text>}
      renderItem={({ item }) => (
        <Pressable
          style={styles.card}
          onPress={() => navigation.navigate('NaryadView', { id: item.id, title: item.title })}
        >
          <IconHeading name="train" textStyle={styles.title}>{item.title}</IconHeading>
          <Text style={styles.meta}>
            {item.naryad_date || ''}
            {item.has_personal ? ' · есть ваш файл' : ''}
            {item.has_pdf ? ' · PDF' : ''}
          </Text>
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
  meta: { color: '#9ca3af', marginTop: 6 },
  muted: { color: '#9ca3af', textAlign: 'center', marginTop: 40 },
});
