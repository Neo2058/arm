import { useCallback, useEffect, useState } from 'react';
import { ActivityIndicator, AppState, Image, StyleSheet, Text, View } from 'react-native';
import { useFocusEffect } from '@react-navigation/native';
import { WebView } from 'react-native-webview';
import * as ScreenCapture from 'expo-screen-capture';
import { API_URL } from '../api';
import { getAttachment } from '../discipline/api';
import { errorMessage } from '../discipline/contract.cjs';
import { apiOrigin, arrayBufferToBase64, buildSecurePdfHtml, isAllowedViewerUrl } from '../securePdfViewerHtml';
import s from '../discipline/styles';

export default function DisciplineAttachmentScreen({ route }) {
  const { file } = route.params;
  const [content, setContent] = useState(null);
  const [error, setError] = useState('');
  const [hidden, setHidden] = useState(false);
  const origin = apiOrigin(API_URL);
  useFocusEffect(useCallback(() => {
    ScreenCapture.preventScreenCaptureAsync('discipline-attachment').catch(() => {});
    const sub = AppState.addEventListener('change', state => setHidden(state !== 'active'));
    return () => { sub.remove(); ScreenCapture.allowScreenCaptureAsync('discipline-attachment').catch(() => {}); };
  }, []));
  useEffect(() => {
    let cancelled = false;
    (async () => {
      try {
        if (!['application/pdf', 'image/jpeg', 'image/png'].includes(file.mime)) throw new Error('Unsupported attachment');
        const bytes = await getAttachment(file.id);
        if (!cancelled) setContent(arrayBufferToBase64(bytes));
      } catch (e) { if (!cancelled) setError(errorMessage(e)); }
    })();
    return () => { cancelled = true; };
  }, [file.id, file.mime]);
  if (error) return <View style={s.center}><Text style={s.error}>{error}</Text></View>;
  if (!content) return <View style={s.center}><ActivityIndicator color="#fb923c" /><Text style={s.muted}>{file.name}</Text></View>;
  return <View style={s.page}>
    {file.mime === 'application/pdf' ? <WebView
      source={{ html: buildSecurePdfHtml(content, origin), baseUrl: origin || undefined }}
      style={s.page} incognito cacheEnabled={false} allowFileAccess={false} allowsLinkPreview={false}
      setSupportMultipleWindows={false} onOpenWindow={() => {}} onFileDownload={() => {}}
      onShouldStartLoadWithRequest={request => isAllowedViewerUrl(request.url || '', origin)}
    /> : <Image accessibilityLabel={file.name} source={{ uri: `data:${file.mime};base64,${content}` }} resizeMode="contain" style={{ flex: 1 }} />}
    {hidden && <View style={[StyleSheet.absoluteFillObject, s.center]}><Text style={s.body}>Вложение скрыто</Text></View>}
  </View>;
}
