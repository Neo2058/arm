const PACKAGE = 'ru.tch15.arm';

function originFromApi(apiUrl) {
  return String(apiUrl || '').replace(/\/api\/mobile\/?$/, '');
}

function manifestUrl(apiUrl) {
  const origin = originFromApi(apiUrl);
  return origin ? `${origin}/install/android.json` : '';
}

function apkUrl(apiUrl) {
  const origin = originFromApi(apiUrl);
  return origin ? `${origin}/install/android` : '';
}

function localVersion(constants) {
  const code = Number(constants?.nativeBuildVersion || constants?.expoConfig?.android?.versionCode || 0);
  const version = String(constants?.nativeAppVersion || constants?.expoConfig?.version || '');
  return { versionCode: Number.isInteger(code) ? code : 0, version };
}

function decide(local, remote) {
  if (!remote || remote.package !== PACKAGE) return { action: 'none' };
  const remoteCode = Number(remote.version_code);
  if (!Number.isInteger(remoteCode) || remoteCode < 1 || !Number.isInteger(local?.versionCode) || local.versionCode < 1) {
    return { action: 'none' };
  }
  if (remoteCode > local.versionCode) {
    return {
      action: 'update',
      version: String(remote.version || ''),
      versionCode: remoteCode,
      sha256: String(remote.sha256 || ''),
      size: Number(remote.size) || 0,
    };
  }
  return { action: 'current' };
}

function isStandalone(constants) {
  return constants?.executionEnvironment !== 'storeClient';
}

module.exports = { PACKAGE, originFromApi, manifestUrl, apkUrl, localVersion, decide, isStandalone };
