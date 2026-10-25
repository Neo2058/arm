import { useEffect, useState } from 'react';
import { ActivityIndicator, StyleSheet, Text, View } from 'react-native';
import { WebView } from 'react-native-webview';
import { API_URL } from '../api';
import {
  apiOrigin,
  arrayBufferToBase64,
  buildSecurePdfHtml,
  isAllowedViewerUrl,
} from '../securePdfViewerHtml';

export default function ChatPdfScreen({ route }) {
  const url = route.params?.url;
  const title = route.params?.title || 'PDF';
  const origin = apiOrigin(API_URL);
  const [html, setHtml] = useState(null);
  const [error, setError] = useState('');

  useEffect(() => {
    let cancelled = false;
    if (!url) {
      setError('Файл недоступен');
      return undefined;
    }
    (async () => {
      try {
        const response = await fetch(url, { headers: { Accept: 'application/pdf' } });
        if (!response.ok) throw new Error('pdf http');
        const buffer = await response.arrayBuffer();
        if (cancelled) return;
        setHtml(buildSecurePdfHtml(arrayBufferToBase64(buffer), origin, { reportViolations: false }));
      } catch {
        if (!cancelled) setError('Файл недоступен');
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [url, origin]);

  if (error) {
    return (
      <View style={styles.center}>
        <Text style={styles.error}>{error}</Text>
      </View>
    );
  }

  if (!html) {
    return (
      <View style={styles.center}>
        <ActivityIndicator color="#fb923c" />
        <Text style={styles.muted}>{title}</Text>
      </View>
    );
  }

  return (
    <WebView
      source={{ html, baseUrl: origin || undefined }}
      style={styles.web}
      originWhitelist={['*']}
      javaScriptEnabled
      incognito
      cacheEnabled={false}
      setSupportMultipleWindows={false}
      allowsLinkPreview={false}
      nestedScrollEnabled
      allowFileAccess={false}
      onShouldStartLoadWithRequest={(request) => {
        const href = request.url || '';
        if (isAllowedViewerUrl(href, origin)) return true;
        if (origin && (href === origin || href === `${origin}/`)) return true;
        return false;
      }}
    />
  );
}

const styles = StyleSheet.create({
  web: { flex: 1, backgroundColor: '#0b1018' },
  center: { flex: 1, backgroundColor: '#0b1018', justifyContent: 'center', alignItems: 'center', padding: 24 },
  muted: { color: '#9ca3af', marginTop: 12 },
  error: { color: '#f87171', textAlign: 'center' },
});
