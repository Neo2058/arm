import { useCallback, useEffect, useRef, useState } from 'react';
import { ActivityIndicator, Alert, AppState, Platform, StyleSheet, Text, View } from 'react-native';
import { useFocusEffect } from '@react-navigation/native';
import { WebView } from 'react-native-webview';
import * as ScreenCapture from 'expo-screen-capture';
import { API_URL, client } from '../api';
import {
  apiOrigin,
  arrayBufferToBase64,
  buildSecurePdfHtml,
  isAllowedViewerUrl,
} from '../securePdfViewerHtml';

const KIND_HINT = {
  screenshot: 'Снимки экрана запрещены. Попытка зафиксирована.',
  recording: 'Запись экрана запрещена. Попытка зафиксирована.',
  download: 'Скачивание запрещено. Документ доступен только для чтения.',
  share: 'Отправка файла запрещена. Попытка зафиксирована.',
  print: 'Печать запрещена. Попытка зафиксирована.',
};

export default function DocumentViewScreen({ route }) {
  const { id, title, kind = 'documents' } = route.params;
  const [html, setHtml] = useState(null);
  const [error, setError] = useState('');
  const [privacyMask, setPrivacyMask] = useState(false);
  const lastReport = useRef({});
  const origin = apiOrigin(API_URL);
  const reportsViolations = kind === 'documents';

  const report = useCallback(
    async (attempt) => {
      const now = Date.now();
      if (now - (lastReport.current[attempt] || 0) < 8000) return;
      lastReport.current[attempt] = now;
      if (reportsViolations) {
        try {
          await client.post(`/documents/${id}/violation`, { kind: attempt });
        } catch {
          // local block still applies
        }
      }
      Alert.alert('Запрещено', KIND_HINT[attempt] || KIND_HINT.download);
    },
    [id, reportsViolations]
  );

  useFocusEffect(
    useCallback(() => {
      ScreenCapture.preventScreenCaptureAsync('document-view').catch(() => {});
      if (Platform.OS === 'ios') {
        ScreenCapture.enableAppSwitcherProtectionAsync(0.9).catch(() => {});
      }
      let sub = { remove() {} };
      try {
        const next = ScreenCapture.addScreenshotListener(() => {
          report('screenshot');
        });
        if (next && typeof next.remove === 'function') {
          sub = next;
        }
      } catch {
        // native module missing until the next APK
      }
      return () => {
        sub.remove();
        ScreenCapture.allowScreenCaptureAsync('document-view').catch(() => {});
        if (Platform.OS === 'ios') {
          ScreenCapture.disableAppSwitcherProtectionAsync().catch(() => {});
        }
      };
    }, [report])
  );

  useEffect(() => {
    const sub = AppState.addEventListener('change', (state) => {
      setPrivacyMask(state !== 'active');
    });
    return () => sub.remove();
  }, []);

  useEffect(() => {
    let cancelled = false;
    (async () => {
      try {
        const { data } = await client.get(`/${kind}/${id}`);
        if (!data?.url) throw new Error('no url');
        const response = await fetch(data.url, {
          headers: { Accept: 'application/pdf' },
        });
        if (!response.ok) throw new Error('pdf http');
        const buffer = await response.arrayBuffer();
        if (cancelled) return;
        setHtml(buildSecurePdfHtml(arrayBufferToBase64(buffer), origin));
      } catch {
        if (!cancelled) setError('Нет доступа или файл недоступен');
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [id, kind, origin]);

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
    <View style={styles.wrap}>
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
        onFileDownload={() => {
          report('download');
        }}
        onOpenWindow={() => {
          report('share');
        }}
        onShouldStartLoadWithRequest={(request) => {
          const url = request.url || '';
          if (isAllowedViewerUrl(url, origin)) return true;
          if (origin && (url === origin || url === `${origin}/`)) return true;
          const lower = url.toLowerCase();
          if (lower.includes('.pdf') || lower.includes('/files/documents/') || request.navigationType === 'click') {
            report('download');
          }
          return false;
        }}
        onMessage={(event) => {
          try {
            const payload = JSON.parse(event.nativeEvent.data);
            if (payload?.type === 'download' || payload?.type === 'share' || payload?.type === 'print') {
              report(payload.type);
            }
          } catch {
            // ignore malformed messages
          }
        }}
      />
      {privacyMask ? <View style={styles.mask} pointerEvents="none" /> : null}
    </View>
  );
}

const styles = StyleSheet.create({
  wrap: { flex: 1, backgroundColor: '#0b1018' },
  web: { flex: 1, backgroundColor: '#0b1018' },
  center: { flex: 1, backgroundColor: '#0b1018', justifyContent: 'center', alignItems: 'center', padding: 24 },
  muted: { color: '#9ca3af', marginTop: 12 },
  error: { color: '#f87171', textAlign: 'center' },
  mask: { ...StyleSheet.absoluteFillObject, backgroundColor: '#0b1018' },
});
