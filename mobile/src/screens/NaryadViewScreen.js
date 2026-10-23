import { useEffect, useState } from 'react';
import { ActivityIndicator, Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { client } from '../api';

export default function NaryadViewScreen({ route, navigation }) {
  const { id, title } = route.params;
  const [naryad, setNaryad] = useState(null);
  const [error, setError] = useState('');

  useEffect(() => {
    let cancelled = false;
    (async () => {
      try {
        const { data } = await client.get(`/naryads/${id}`);
        if (!cancelled) setNaryad(data);
      } catch {
        if (!cancelled) setError('Наряд недоступен');
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [id]);

  if (error) {
    return (
      <View style={styles.center}>
        <Text style={styles.error}>{error}</Text>
      </View>
    );
  }

  if (!naryad) {
    return (
      <View style={styles.center}>
        <ActivityIndicator color="#fb923c" />
        <Text style={styles.muted}>{title}</Text>
      </View>
    );
  }

  return (
    <ScrollView style={styles.wrap} contentContainerStyle={{ padding: 16, paddingBottom: 40 }}>
      <Text style={styles.date}>{naryad.naryad_date}</Text>
      {naryad.url ? (
        <Pressable
          style={styles.primary}
          onPress={() =>
            navigation.navigate('DocumentView', {
              id: naryad.id,
              title: naryad.title,
              kind: 'naryads',
            })
          }
        >
          <Text style={styles.primaryText}>Открыть общий PDF</Text>
        </Pressable>
      ) : null}
      {naryad.personal_text ? (
        <View style={styles.card}>
          <Text style={styles.label}>{naryad.personal_name || 'Ваш наряд'}</Text>
          <Text selectable style={styles.body}>
            {naryad.personal_text}
          </Text>
        </View>
      ) : (
        <Text style={styles.muted}>Личного файла в этой выкладке нет</Text>
      )}
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  wrap: { flex: 1, backgroundColor: '#0b1018' },
  center: { flex: 1, backgroundColor: '#0b1018', justifyContent: 'center', alignItems: 'center', padding: 24 },
  date: { color: '#fb923c', fontWeight: '700', marginBottom: 12 },
  primary: { backgroundColor: '#ea580c', borderRadius: 14, padding: 14, alignItems: 'center', marginBottom: 16 },
  primaryText: { color: '#fff', fontWeight: '700' },
  card: { backgroundColor: '#151b24', borderRadius: 16, padding: 16 },
  label: { color: '#fb923c', marginBottom: 10, fontWeight: '700' },
  body: { color: '#e5e7eb', fontFamily: 'monospace', lineHeight: 20 },
  muted: { color: '#9ca3af', marginTop: 12, textAlign: 'center' },
  error: { color: '#f87171', textAlign: 'center' },
});
