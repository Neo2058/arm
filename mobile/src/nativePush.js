import { PermissionsAndroid, Platform } from 'react-native';

function nativeModule() {
  try {
    return require('arm-notification-poller');
  } catch {
    return null;
  }
}

export async function requestNotifyPermission() {
  if (Platform.OS !== 'android') return true;
  const version = typeof Platform.Version === 'number' ? Platform.Version : parseInt(String(Platform.Version), 10);
  if (!Number.isFinite(version) || version < 33) return true;
  try {
    const result = await PermissionsAndroid.request(PermissionsAndroid.PERMISSIONS.POST_NOTIFICATIONS);
    return result === PermissionsAndroid.RESULTS.GRANTED;
  } catch {
    return false;
  }
}

export async function startNativePoller(token, apiUrl) {
  const native = nativeModule();
  if (!native?.start || !token || !apiUrl) return false;
  try {
    await native.start(String(token), String(apiUrl));
    return true;
  } catch {
    return false;
  }
}

export async function stopNativePoller() {
  const native = nativeModule();
  if (!native?.stop) return;
  try {
    await native.stop();
  } catch {
    // logout still clears the JS session
  }
}

export async function setNativeForeground(value) {
  const native = nativeModule();
  if (!native?.setForeground) return;
  try {
    await native.setForeground(value === true);
  } catch {
    // next heartbeat retries
  }
}
