const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {
  apkUrl,
  canonicalFields,
  canonicalPayload,
  CERTIFICATE,
  decide,
  formatSize,
  isStandalone,
  localVersion,
  manifestUrl,
  METADATA_PUBLIC_KEY,
  PACKAGE,
  sanitizeChanges,
  sha256,
  verifyDownload,
} = require('../src/app-update.cjs');

const api = 'https://www.xn---15-7edz.xn--p1ai/api/mobile';
const hash = 'a'.repeat(64);
const remote = {
  package: PACKAGE,
  version: '1.1.6',
  version_code: 22,
  sha256: hash,
  size: 10,
  url: '/install/android',
  certificate_sha256: CERTIFICATE,
  changes: ['Дни недели в календаре', '  ', 1, '<script>'],
};
const signed = { signatureVerified: true };
const golden = '{"certificate_sha256":"2d0cdbf6e20385dfab263e4b09cc0729fbbb6fa80074866913df8bd8c9e3c99f","changes":["Дни недели в календаре"],"package":"ru.tch15.arm","sha256":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","size":9,"url":"/install/android","version":"1.1.12","version_code":28}';

test('manifest and apk URLs stay on the install origin and ignore a foreign download path', () => {
  assert.equal(manifestUrl(api), 'https://www.xn---15-7edz.xn--p1ai/install/android.json');
  assert.equal(apkUrl(api), 'https://www.xn---15-7edz.xn--p1ai/install/android');
  assert.equal(manifestUrl(''), '');
});

test('newer signed package is offered, current and foreign builds are ignored', () => {
  assert.equal(decide({ versionCode: 21 }, remote).action, 'none');
  const offer = decide({ versionCode: 21 }, remote, signed);
  assert.equal(offer.action, 'update');
  assert.deepEqual(offer.changes, ['Дни недели в календаре', '<script>']);
  assert.equal(offer.sha256, hash);
  assert.equal(offer.certificateSha256, CERTIFICATE);
  assert.equal(decide({ versionCode: 22 }, remote, signed).action, 'current');
  assert.equal(decide({ versionCode: 23 }, remote, signed).action, 'current');
  assert.equal(decide({ versionCode: 21 }, { ...remote, package: 'ru.tch15.arm.monitor' }, signed).action, 'none');
  assert.equal(decide({ versionCode: 0 }, remote, signed).action, 'none');
  assert.equal(decide({ versionCode: 21 }, null, signed).action, 'none');
  assert.equal(decide({ versionCode: 21 }, { ...remote, sha256: 'not-a-hash' }, signed).action, 'none');
  assert.equal(decide({ versionCode: 21 }, { ...remote, url: 'https://evil.example/payload.apk' }, signed).action, 'none');
  assert.equal(decide({ versionCode: 21 }, { ...remote, certificate_sha256: 'b'.repeat(64) }, signed).action, 'none');
});

test('canonical payload matches PHP and pinned public keys stay in lockstep', () => {
  assert.equal(canonicalPayload({
    package: PACKAGE,
    version: '1.1.12',
    version_code: 28,
    sha256: hash,
    size: 9,
    url: '/install/android',
    certificate_sha256: CERTIFICATE,
    changes: ['Дни недели в календаре'],
  }), golden);
  assert.equal(METADATA_PUBLIC_KEY.length, 182);
  const php = fs.readFileSync(path.join(__dirname, '../../config/install.php'), 'utf8');
  const kotlin = fs.readFileSync(path.join(__dirname, '../modules/arm-notification-poller/android/src/main/java/ru/tch15/arm/push/ReleaseMetadata.kt'), 'utf8');
  const publisher = fs.readFileSync(path.join(__dirname, '../scripts/publish_installers.py'), 'utf8');
  assert.match(php, new RegExp(METADATA_PUBLIC_KEY));
  assert.match(kotlin, new RegExp(METADATA_PUBLIC_KEY));
  assert.match(publisher, new RegExp(METADATA_PUBLIC_KEY));
});

test('release notes are bounded and the overlay lists them', () => {
  assert.deepEqual(sanitizeChanges(['  Одна правка  ', '', 3, 'x'.repeat(200)]), ['Одна правка', 'x'.repeat(160)]);
  assert.equal(sanitizeChanges(Array.from({ length: 12 }, (_, i) => `пункт ${i}`)).length, 8);
  assert.equal(sha256('g'.repeat(64)), '');
  assert.equal(sha256('a'.repeat(63)), '');
  assert.equal(formatSize(49105838), '46,8 МБ');
  assert.deepEqual(verifyDownload({
    expectedSha256: hash, actualSha256: hash, expectedSize: 10, actualSize: 10,
    expectedPackage: PACKAGE, actualPackage: PACKAGE,
    expectedCertificate: CERTIFICATE, actualCertificate: CERTIFICATE,
    expectedVersionCode: 22, actualVersionCode: 22,
  }), { ok: true });
  assert.equal(verifyDownload({
    expectedSha256: hash, actualSha256: 'b'.repeat(64), expectedSize: 10, actualSize: 10,
    expectedPackage: PACKAGE, actualPackage: PACKAGE,
    expectedCertificate: CERTIFICATE, actualCertificate: CERTIFICATE,
    expectedVersionCode: 22, actualVersionCode: 22,
  }).reason, 'hash');
  assert.equal(verifyDownload({
    expectedSha256: hash, actualSha256: hash, expectedSize: 10, actualSize: 10,
    expectedPackage: PACKAGE, actualPackage: 'ru.tch15.arm.monitor',
    expectedCertificate: CERTIFICATE, actualCertificate: CERTIFICATE,
    expectedVersionCode: 22, actualVersionCode: 22,
  }).reason, 'package');
  assert.equal(verifyDownload({
    expectedSha256: hash, actualSha256: hash, expectedSize: 10, actualSize: 10,
    expectedPackage: PACKAGE, actualPackage: PACKAGE,
    expectedCertificate: CERTIFICATE, actualCertificate: 'b'.repeat(64),
    expectedVersionCode: 22, actualVersionCode: 22,
  }).reason, 'certificate');
  assert.equal(verifyDownload({
    expectedSha256: hash, actualSha256: hash, expectedSize: 10, actualSize: 10,
    expectedPackage: PACKAGE, actualPackage: PACKAGE,
    expectedCertificate: CERTIFICATE, actualCertificate: CERTIFICATE,
    expectedVersionCode: 22, actualVersionCode: 21,
  }).reason, 'version');
  const src = fs.readFileSync(path.join(__dirname, '../src/AppUpdate.js'), 'utf8');
  assert.match(src, /offer\.changes/);
  assert.match(src, /Что изменилось/);
  assert.match(src, /verifyDownload/);
  assert.match(src, /verifyReleaseSignature/);
  assert.match(src, /inspectApk/);
  assert.match(src, /sha256File/);
  assert.match(src, /Проверка файла/);
  assert.match(src, /не прошёл проверку/);
  assert.match(src, /приложение само проверит файл/);
  assert.doesNotMatch(src, /SHA-256 /);
  assert.match(src, /При расхождении не устанавливайте/);
  const digest = fs.readFileSync(path.join(__dirname, '../modules/arm-notification-poller/android/src/main/java/ru/tch15/arm/push/FileDigest.kt'), 'utf8');
  assert.match(digest, /MessageDigest\.getInstance\("SHA-256"\)/);
  assert.match(digest, /cacheDir/);
  const inspector = fs.readFileSync(path.join(__dirname, '../modules/arm-notification-poller/android/src/main/java/ru/tch15/arm/push/ApkInspector.kt'), 'utf8');
  assert.match(inspector, /GET_SIGNING_CERTIFICATES|GET_SIGNATURES/);
  assert.match(inspector, /certificateSha256/);
  const poller = fs.readFileSync(path.join(__dirname, '../modules/arm-notification-poller/index.js'), 'utf8');
  assert.match(poller, /sha256File/);
  assert.match(poller, /inspectApk/);
  assert.match(poller, /verifyReleaseSignature/);
  const config = fs.readFileSync(path.join(__dirname, '../app.config.js'), 'utf8');
  const versionCode = config.match(/versionCode:\s*monitor \? 1 : (\d+)/);
  assert.ok(versionCode);
  const notes = JSON.parse(fs.readFileSync(path.join(__dirname, '../android-release-notes.json'), 'utf8'));
  const changes = notes[versionCode[1]];
  assert.ok(Array.isArray(changes) && changes.length >= 1 && changes.length <= 8);
  for (const line of changes) {
    assert.equal(typeof line, 'string');
    assert.ok(line.trim().length > 0 && line.length <= 160);
  }
});

test('metadata signature verifies with the pinned public key shape', () => {
  const { createPublicKey, generateKeyPairSync, sign, verify } = require('crypto');
  const { spawnSync } = require('node:child_process');
  const { privateKey, publicKey } = generateKeyPairSync('ec', { namedCurve: 'P-256' });
  const pub = publicKey.export({ format: 'der', type: 'spki' }).toString('hex');
  const message = canonicalPayload({
    package: PACKAGE,
    version: '1.1.12',
    version_code: 28,
    sha256: hash,
    size: 9,
    url: '/install/android',
    certificate_sha256: CERTIFICATE,
    changes: ['Дни недели в календаре'],
  });
  const signatureHex = sign('SHA256', Buffer.from(message), privateKey).toString('hex');
  const nodeVerify = (msg, sig, keyHex) => verify(
    'SHA256',
    Buffer.from(msg),
    createPublicKey({ key: Buffer.from(keyHex, 'hex'), format: 'der', type: 'spki' }),
    Buffer.from(sig, 'hex'),
  );
  assert.equal(nodeVerify(message, signatureHex, pub), true);
  assert.equal(nodeVerify(message + 'x', signatureHex, pub), false);
  assert.equal(nodeVerify(message, signatureHex, METADATA_PUBLIC_KEY), false);
  assert.equal(canonicalFields(remote).url, '/install/android');
  const src = fs.readFileSync(path.join(__dirname, '../src/app-update.cjs'), 'utf8');
  assert.doesNotMatch(src, /require\(['"]crypto['"]\)/);
  const py = spawnSync('python3', ['-c',
    'from cryptography.hazmat.primitives import hashes, serialization\n'
    + 'from cryptography.hazmat.primitives.asymmetric import ec\n'
    + 'import sys\n'
    + 'serialization.load_der_public_key(bytes.fromhex(sys.argv[1])).verify('
    + 'bytes.fromhex(sys.argv[2]), sys.argv[3].encode(), ec.ECDSA(hashes.SHA256()))\n'
    + 'print("ok")\n',
    pub, signatureHex, message,
  ], { encoding: 'utf8' });
  assert.equal(py.status, 0, py.stderr);
  assert.equal(py.stdout.trim(), 'ok');
  const php = spawnSync('php', ['-r',
    '$der=hex2bin($argv[1]); $sig=hex2bin($argv[2]); $msg=$argv[3];'
    + '$pem="-----BEGIN PUBLIC KEY-----\\n".chunk_split(base64_encode($der),64,"\\n")."-----END PUBLIC KEY-----\\n";'
    + 'exit(openssl_verify($msg,$sig,openssl_pkey_get_public($pem),OPENSSL_ALGO_SHA256)===1?0:1);',
    '--', pub, signatureHex, message,
  ], { encoding: 'utf8' });
  assert.equal(php.status, 0, php.stderr);
});

test('native version prefers the installed binary and skips Expo Go', () => {
  assert.deepEqual(localVersion({ nativeBuildVersion: '22', nativeAppVersion: '1.1.6', expoConfig: { version: '1.1.5', android: { versionCode: 21 } } }), { versionCode: 22, version: '1.1.6' });
  assert.equal(isStandalone({ executionEnvironment: 'bare' }), true);
  assert.equal(isStandalone({ executionEnvironment: 'storeClient' }), false);
});
