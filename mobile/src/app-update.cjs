const PACKAGE = 'ru.tch15.arm';
const CERTIFICATE = '2d0cdbf6e20385dfab263e4b09cc0729fbbb6fa80074866913df8bd8c9e3c99f';
const METADATA_PUBLIC_KEY = '3059301306072a8648ce3d020106082a8648ce3d03010703420004ba84876f20b70a1e138dcd3eade2a51f6a9a188b5056e3cc39a5e0503fb2602f42da6da823b7533a9fe20b2ed6fb64725ade829cac49975836dfa19e4f402848';
const INSTALL_URL = '/install/android';
const MAX_CHANGES = 8;
const MAX_CHANGE_LEN = 160;

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

function sanitizeChanges(value) {
  if (!Array.isArray(value)) return [];
  const out = [];
  for (const item of value) {
    if (typeof item !== 'string') continue;
    const text = item.replace(/[\u0000-\u001F\u007F]/g, ' ').replace(/\s+/g, ' ').trim();
    if (!text) continue;
    out.push(text.slice(0, MAX_CHANGE_LEN));
    if (out.length >= MAX_CHANGES) break;
  }
  return out;
}

function sha256(value) {
  const text = String(value || '').trim().toLowerCase();
  return /^[a-f0-9]{64}$/.test(text) ? text : '';
}

function signature(value) {
  const text = String(value || '').trim().toLowerCase();
  return /^[a-f0-9]{128,180}$/.test(text) ? text : '';
}

function formatSize(bytes) {
  const n = Number(bytes);
  if (!Number.isFinite(n) || n < 1) return '';
  return `${(n / 1048576).toFixed(1).replace('.', ',')} МБ`;
}

function canonicalFields(remote) {
  const versionCode = Number(remote?.version_code);
  const size = Number(remote?.size);
  const digest = sha256(remote?.sha256);
  const cert = sha256(remote?.certificate_sha256);
  const version = String(remote?.version || '');
  const changes = sanitizeChanges(remote?.changes);
  if (!remote || remote.package !== PACKAGE || remote.url !== INSTALL_URL || version === '' || !Number.isInteger(versionCode) || versionCode < 1 || !Number.isInteger(size) || size < 1 || !digest || cert !== CERTIFICATE || changes.length < 1) {
    return null;
  }
  return {
    certificate_sha256: cert,
    changes,
    package: PACKAGE,
    sha256: digest,
    size,
    url: INSTALL_URL,
    version,
    version_code: versionCode,
  };
}

function canonicalPayload(fields) {
  const normalized = canonicalFields(fields);
  return normalized ? JSON.stringify(normalized) : '';
}

function decide(local, remote, options = {}) {
  if (!options.signatureVerified) return { action: 'none' };
  const fields = canonicalFields(remote);
  if (!fields) return { action: 'none' };
  if (!Number.isInteger(local?.versionCode) || local.versionCode < 1) return { action: 'none' };
  if (fields.version_code > local.versionCode) {
    return {
      action: 'update',
      version: fields.version,
      versionCode: fields.version_code,
      sha256: fields.sha256,
      size: fields.size,
      changes: fields.changes,
      certificateSha256: fields.certificate_sha256,
    };
  }
  return { action: 'current' };
}

function verifyDownload({
  expectedSha256,
  actualSha256,
  expectedSize,
  actualSize,
  expectedPackage,
  actualPackage,
  expectedCertificate,
  actualCertificate,
  expectedVersionCode,
  actualVersionCode,
}) {
  const expected = sha256(expectedSha256);
  const actual = sha256(actualSha256);
  const size = Number(actualSize);
  const versionCode = Number(actualVersionCode);
  if (!Number.isFinite(size) || size < 1) return { ok: false, reason: 'empty' };
  if (Number(expectedSize) > 0 && size !== Number(expectedSize)) return { ok: false, reason: 'size' };
  if (!expected || !actual || expected !== actual) return { ok: false, reason: 'hash' };
  if (String(actualPackage || '') !== PACKAGE || String(expectedPackage || '') !== PACKAGE) return { ok: false, reason: 'package' };
  if (sha256(actualCertificate) !== CERTIFICATE || sha256(expectedCertificate) !== CERTIFICATE) return { ok: false, reason: 'certificate' };
  if (!Number.isInteger(versionCode) || versionCode < 1 || versionCode !== Number(expectedVersionCode)) return { ok: false, reason: 'version' };
  return { ok: true };
}

function isStandalone(constants) {
  return constants?.executionEnvironment !== 'storeClient';
}

module.exports = {
  PACKAGE,
  CERTIFICATE,
  METADATA_PUBLIC_KEY,
  INSTALL_URL,
  originFromApi,
  manifestUrl,
  apkUrl,
  localVersion,
  decide,
  verifyDownload,
  canonicalFields,
  canonicalPayload,
  isStandalone,
  sanitizeChanges,
  sha256,
  signature,
  formatSize,
  MAX_CHANGES,
  MAX_CHANGE_LEN,
};
