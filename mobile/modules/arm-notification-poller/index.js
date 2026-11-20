const { requireOptionalNativeModule } = require('expo-modules-core');

const Native = requireOptionalNativeModule('ArmNotificationPoller');

async function start(token, apiUrl) {
  if (!Native) return false;
  await Native.start(String(token || ''), String(apiUrl || ''));
  return true;
}

async function stop() {
  if (!Native) return false;
  await Native.stop();
  return true;
}

async function setForeground(value) {
  if (!Native) return false;
  await Native.setForeground(value === true);
  return true;
}

async function sha256File(uri) {
  if (!Native?.sha256File) {
    throw new Error('Native digest unavailable');
  }
  const hex = String(await Native.sha256File(String(uri || ''))).trim().toLowerCase();
  if (!/^[a-f0-9]{64}$/.test(hex)) {
    throw new Error('Invalid digest');
  }
  return hex;
}

async function inspectApk(uri) {
  if (!Native?.inspectApk) {
    throw new Error('Native inspector unavailable');
  }
  const info = await Native.inspectApk(String(uri || ''));
  return {
    package: String(info?.package || ''),
    versionCode: Number(info?.versionCode) || 0,
    certificateSha256: String(info?.certificateSha256 || '').trim().toLowerCase(),
  };
}

async function verifyReleaseSignature(message, signatureHex) {
  if (!Native?.verifyReleaseSignature) return false;
  try {
    return (await Native.verifyReleaseSignature(String(message || ''), String(signatureHex || ''))) === true;
  } catch {
    return false;
  }
}

module.exports = { start, stop, setForeground, sha256File, inspectApk, verifyReleaseSignature };
