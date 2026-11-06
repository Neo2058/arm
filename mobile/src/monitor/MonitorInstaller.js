import { useState } from 'react';
import { Platform, Pressable, Text, View } from 'react-native';
import * as FileSystem from 'expo-file-system/legacy';
import * as SecureStore from 'expo-secure-store';
import * as IntentLauncher from 'expo-intent-launcher';
import { API_URL } from '../api';

// No bearer token in URLs, browser history or shared storage.
export default function MonitorInstaller() {
  const [busy, setBusy] = useState(false), [message, setMessage] = useState('');
  const install = async () => {
    if (busy) return;
    setBusy(true); setMessage('Скачивание защищённого установщика…');
    const path = FileSystem.cacheDirectory + 'tch15-monitor.apk';
    try {
      const token = await SecureStore.getItemAsync('token');
      if (!token) throw new Error('Login required');
      const result = await FileSystem.downloadAsync(API_URL.replace(/\/mobile\/?$/, '/monitor/installer'), path, {
        headers: { Authorization: `Bearer ${token}`, Accept: 'application/vnd.android.package-archive' },
      });
      if (result.status !== 200) throw new Error('Download rejected');
      const info = await FileSystem.getInfoAsync(path);
      if (!info.exists || !info.size) throw new Error('Empty installer');
      const uri = await FileSystem.getContentUriAsync(path);
      setMessage('Если Android запросит разрешение, разрешите установку из ТЧ-15. После этого при необходимости нажмите кнопку ещё раз.');
      await IntentLauncher.startActivityAsync('android.intent.action.VIEW', {
        data: uri, flags: 1, type: 'application/vnd.android.package-archive',
      });
    } catch {
      setMessage('Не удалось открыть установщик. Проверьте сеть, права суперадминистратора и разрешение Android на установку из ТЧ-15.');
    } finally {
      await FileSystem.deleteAsync(path, { idempotent: true }).catch(() => {});
      setBusy(false);
    }
  };
  if (Platform.OS !== 'android') return null;
  return <View style={{ padding: 18, borderRadius: 20, backgroundColor: '#162131', gap: 12 }}>
    <Text style={{ color: '#fb923c', fontSize: 18, fontWeight: '700' }}>Мониторинг ресурса</Text>
    <Text style={{ color: '#a9b8c9', lineHeight: 21 }}>Отдельное приложение для суперадминистраторов: сервер, сервисы и предупреждения.</Text>
    <Pressable disabled={busy} onPress={install} style={{ backgroundColor: '#c2410c', borderRadius: 12, padding: 16 }}>
      <Text style={{ color: '#fff', fontWeight: '600' }}>{busy ? 'Подготовка…' : 'Скачать и установить мониторинг'}</Text>
    </Pressable>
    {!!message && <Text accessibilityRole="alert" style={{ color: '#cbd5e1', lineHeight: 21 }}>{message}</Text>}
  </View>;
}
