import { useEffect, useState } from 'react';
import { ActivityIndicator, StyleSheet, Text, View } from 'react-native';
import { WebView } from 'react-native-webview';
import { client } from '../api';

export default function DocumentViewScreen({ route }) {
  const { id, title } = route.params;
  const [url, setUrl] = useState(null);
  const [error, setError] = useState('');

  useEffect(() => {
    let cancelled = false;
    (async () => {
      try {
        const { data } = await client.get(`/documents/${id}`);
        if (!cancelled) setUrl(data.url);
      } catch {
        if (!cancelled) setError('Нет доступа или файл недоступен');
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

  if (!url) {
    return (
      <View style={styles.center}>
        <ActivityIndicator color="#fb923c" />
        <Text style={styles.muted}>{title}</Text>
      </View>
    );
  }

  return <WebView source={{ uri: url }} style={styles.web} originWhitelist={['*']} />;
}

const styles = StyleSheet.create({
  web: { flex: 1, backgroundColor: '#0b1018' },
  center: { flex: 1, backgroundColor: '#0b1018', justifyContent: 'center', alignItems: 'center', padding: 24 },
  muted: { color: '#9ca3af', marginTop: 12 },
  error: { color: '#f87171', textAlign: 'center' },
});
