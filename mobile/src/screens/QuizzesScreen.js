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

export default function QuizzesScreen({ navigation }) {
  const [quizzes, setQuizzes] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  const load = async () => {
    setError('');
    try {
      const { data } = await client.get('/quizzes');
      setQuizzes(data.quizzes || []);
    } catch {
      setError('Не удалось загрузить тесты');
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
      data={quizzes}
      keyExtractor={(item) => String(item.id)}
      refreshControl={<RefreshControl refreshing={false} onRefresh={load} />}
      ListEmptyComponent={<Text style={styles.muted}>{error || 'Доступных тестов нет'}</Text>}
      renderItem={({ item }) => (
        <Pressable
          style={styles.card}
          onPress={() => navigation.navigate('QuizPlay', { id: item.id, title: item.title })}
        >
          <IconHeading name="quiz" textStyle={styles.title}>{item.title}</IconHeading>
          {item.document_title ? <Text style={styles.meta}>{item.document_title}</Text> : null}
          {item.general ? <Text style={styles.meta}>Общая аттестация</Text> : null}
          <Text style={styles.meta}>
            {item.time_limit ? `${item.time_limit} мин` : 'Без лимита'}
            {item.last_total != null ? ` · последний результат ${item.last_score}/${item.last_total}` : ''}
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
