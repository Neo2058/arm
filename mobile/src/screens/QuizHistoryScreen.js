import { useCallback, useRef, useState } from 'react';
import { ActivityIndicator, FlatList, Pressable, RefreshControl, StyleSheet, Text, View } from 'react-native';
import { useFocusEffect } from '@react-navigation/native';
import { client } from '../api';

export default function QuizHistoryScreen() {
  const [results, setResults] = useState([]);
  const [page, setPage] = useState(0);
  const [hasMore, setHasMore] = useState(false);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const generation = useRef(0);
  const busy = useRef(false);

  const load = useCallback(async (nextPage, reset = false) => {
    if (busy.current && !reset) return;
    const current = reset ? ++generation.current : generation.current;
    busy.current = true;
    setLoading(true);
    setError('');
    try {
      const { data } = await client.get('/quizzes/history', { params: { page: nextPage } });
      if (current !== generation.current) return;
      setResults((previous) => reset ? data.results : [...previous, ...data.results.filter((row) => !previous.some((old) => old.id === row.id))]);
      setPage(nextPage);
      setHasMore(data.has_more);
    } catch {
      if (current === generation.current) setError('Не удалось загрузить результаты. Попробуйте ещё раз.');
    } finally {
      if (current === generation.current) {
        busy.current = false;
        setLoading(false);
      }
    }
  }, []);

  useFocusEffect(useCallback(() => {
    load(1, true);
    return () => { generation.current++; busy.current = false; };
  }, [load]));

  return (
    <FlatList
      style={styles.list}
      contentContainerStyle={styles.content}
      data={results}
      keyExtractor={(item) => String(item.id)}
      refreshControl={<RefreshControl refreshing={loading && page <= 1} onRefresh={() => load(1, true)} />}
      ListHeaderComponent={<Text style={styles.meta}>Ваши попытки по доступным сейчас тестам. Новые результаты появляются после завершения теста.</Text>}
      ListEmptyComponent={!loading && !error ? <Text style={styles.meta}>Вы пока не проходили доступные тесты.</Text> : null}
      renderItem={({ item }) => (
        <View style={styles.card}>
          <Text style={styles.title}>{item.title}</Text>
          <Text style={styles.score}>{item.score} из {item.total_questions}</Text>
          <Text style={styles.meta}>{item.completed_at ? new Date(item.completed_at).toLocaleString('ru-RU') : 'Дата не указана'}</Text>
          <Text style={styles.meta}>Время: {Math.floor(item.time_spent / 60)} мин {item.time_spent % 60} сек</Text>
        </View>
      )}
      ListFooterComponent={
        <View>
          {error ? <Text accessibilityRole="alert" style={styles.error}>{error}</Text> : null}
          {loading ? <ActivityIndicator color="#fb923c" /> : (hasMore || error) ? (
            <Pressable accessibilityRole="button" style={styles.button} onPress={() => load(error ? 1 : page + 1, Boolean(error) || page === 0)}>
              <Text style={styles.title}>{error ? 'Повторить загрузку' : 'Показать ещё'}</Text>
            </Pressable>
          ) : null}
        </View>
      }
    />
  );
}

const styles = StyleSheet.create({
  list: { flex: 1, backgroundColor: '#0b1018' },
  content: { padding: 16, paddingBottom: 40 },
  card: { backgroundColor: '#151b24', borderRadius: 16, padding: 16, marginTop: 12 },
  title: { color: '#fff', fontSize: 18, fontWeight: '600' },
  score: { color: '#fb923c', fontSize: 22, marginTop: 8 },
  meta: { color: '#9ca3af', marginVertical: 6 },
  error: { color: '#fca5a5', marginVertical: 12 },
  button: { backgroundColor: '#263244', borderRadius: 12, padding: 14, marginTop: 16 },
});
