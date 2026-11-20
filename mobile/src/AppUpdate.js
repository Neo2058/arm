import { useCallback, useEffect, useRef, useState } from 'react';
import { Platform, Pressable, ScrollView, Text, View } from 'react-native';
import Constants from 'expo-constants';
import * as FileSystem from 'expo-file-system/legacy';
import * as IntentLauncher from 'expo-intent-launcher';
import { API_URL } from './api';
import { apkUrl, canonicalFields, canonicalPayload, CERTIFICATE, decide, formatSize, isStandalone, localVersion, manifestUrl, PACKAGE, verifyDownload } from './app-update.cjs';

export default function AppUpdate() {
  const [offer, setOffer] = useState(null);
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState('');
  const [progress, setProgress] = useState(0);
  const dismissed = useRef(false);
  const check = useCallback(async () => {
    if (Platform.OS !== 'android' || !isStandalone(Constants) || dismissed.current) return;
    const url = manifestUrl(API_URL);
    if (!url) return;
    try {
      const response = await fetch(url, { headers: { Accept: 'application/json' } });
      if (!response.ok) return;
      const remote = await response.json();
      const fields = canonicalFields(remote);
      if (!fields) return;
      const native = require('arm-notification-poller');
      const signatureVerified = await native.verifyReleaseSignature(canonicalPayload(fields), remote.signature);
      if (signatureVerified !== true) return;
      const result = decide(localVersion(Constants), remote, { signatureVerified: true });
      if (result.action === 'update') setOffer(result);
    } catch {
      // Offline or missing manifest: keep the current build.
    }
  }, []);
  useEffect(() => { check(); }, [check]);
  const install = async () => {
    if (busy || !offer) return;
    setBusy(true); setMessage('Скачивание обновления…'); setProgress(0);
    const path = FileSystem.cacheDirectory + 'tch15-update.apk';
    const source = apkUrl(API_URL);
    try {
      await FileSystem.deleteAsync(path, { idempotent: true });
      const task = FileSystem.createDownloadResumable(source, path, {
        headers: { Accept: 'application/vnd.android.package-archive' },
      }, ({ totalBytesWritten, totalBytesExpectedToWrite }) => {
        if (totalBytesExpectedToWrite) setProgress(totalBytesWritten / totalBytesExpectedToWrite);
      });
      const result = await task.downloadAsync();
      if (result.status !== 200) throw new Error('Download rejected');
      const info = await FileSystem.getInfoAsync(path);
      setMessage('Проверка файла…');
      const native = require('arm-notification-poller');
      let actual = '';
      let inspected = {};
      try {
        actual = await native.sha256File(path);
        inspected = await native.inspectApk(path);
      } catch {
        actual = '';
        inspected = {};
      }
      const check = verifyDownload({
        expectedSha256: offer.sha256,
        actualSha256: actual,
        expectedSize: offer.size,
        actualSize: info.size,
        expectedPackage: PACKAGE,
        actualPackage: inspected.package,
        expectedCertificate: offer.certificateSha256 || CERTIFICATE,
        actualCertificate: inspected.certificateSha256,
        expectedVersionCode: offer.versionCode,
        actualVersionCode: inspected.versionCode,
      });
      if (!check.ok) {
        await FileSystem.deleteAsync(path, { idempotent: true });
        throw new Error('verify');
      }
      const uri = await FileSystem.getContentUriAsync(path);
      setMessage('Разрешите установку из ТЧ-15, если Android спросит, затем подтвердите обновление.');
      await IntentLauncher.startActivityAsync('android.intent.action.VIEW', {
        data: uri, flags: 1, type: 'application/vnd.android.package-archive',
      });
    } catch (error) {
      setMessage(error?.message === 'verify'
        ? 'Файл обновления не прошёл проверку. Установка отменена. Сообщите администратору.'
        : 'Не удалось установить обновление. Проверьте сеть и разрешение Android на установку из ТЧ-15, либо скачайте файл на странице установки.');
    } finally {
      setBusy(false);
    }
  };
  if (Platform.OS !== 'android' || !offer) return null;
  const sizeLabel = formatSize(offer.size);
  return <View style={{ position: 'absolute', left: 16, right: 16, bottom: 88, zIndex: 40, backgroundColor: '#162131', borderRadius: 16, padding: 16, gap: 10, borderWidth: 1, borderColor: '#fb923c', maxHeight: '70%' }}>
    <Text style={{ color: '#fff', fontSize: 16, fontWeight: '700' }}>Доступна версия {offer.version || offer.versionCode}</Text>
    <ScrollView style={{ maxHeight: 240 }} nestedScrollEnabled>
      {offer.changes.length ? <>
        <Text style={{ color: '#fff', fontWeight: '700', marginBottom: 6 }}>Что изменилось</Text>
        {offer.changes.map((line, index) => <Text key={index} style={{ color: '#cbd5e1', lineHeight: 20, marginBottom: 4 }}>• {line}</Text>)}
      </> : <Text style={{ color: '#fbd496', lineHeight: 20 }}>Список правок этой версии не получен. Сверяйте файл со страницей тч-15.рф/install.</Text>}
    </ScrollView>
    <Text style={{ color: '#cbd5e1', lineHeight: 20 }}>Пакет ru.tch15.arm{sizeLabel ? ` · ${sizeLabel}` : ''}. Установка поверх текущей, черновики сохраняются. Сверяйте список с ожидаемыми правками ТЧ-15. При расхождении не устанавливайте и сообщите администратору. Перед установкой приложение само проверит файл. Android попросит подтверждение.</Text>
    {busy && <Text style={{ color: '#fbd496' }}>{Math.round(progress * 100)}%</Text>}
    {!!message && <Text accessibilityRole="alert" style={{ color: '#cbd5e1', lineHeight: 20 }}>{message}</Text>}
    <Pressable accessibilityRole="button" disabled={busy} onPress={install} style={{ backgroundColor: '#c2410c', borderRadius: 12, padding: 14, alignItems: 'center' }}>
      <Text style={{ color: '#fff', fontWeight: '700' }}>{busy ? 'Подготовка…' : 'Скачать и установить'}</Text>
    </Pressable>
    <Pressable accessibilityRole="button" disabled={busy} onPress={() => { dismissed.current = true; setOffer(null); }} style={{ padding: 8, alignItems: 'center' }}>
      <Text style={{ color: '#94a3b8' }}>Позже</Text>
    </Pressable>
  </View>;
}
