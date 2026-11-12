const test = require('node:test');
const assert = require('node:assert/strict');
const { apkUrl, decide, isStandalone, localVersion, manifestUrl } = require('../src/app-update.cjs');

const api = 'https://www.xn---15-7edz.xn--p1ai/api/mobile';
const remote = { package: 'ru.tch15.arm', version: '1.1.6', version_code: 22, sha256: 'abc', size: 10, url: 'https://evil.example/payload.apk' };

test('manifest and apk URLs stay on the install origin and ignore a foreign download path', () => {
  assert.equal(manifestUrl(api), 'https://www.xn---15-7edz.xn--p1ai/install/android.json');
  assert.equal(apkUrl(api), 'https://www.xn---15-7edz.xn--p1ai/install/android');
  assert.equal(manifestUrl(''), '');
});

test('newer signed package is offered, current and foreign builds are ignored', () => {
  assert.equal(decide({ versionCode: 21 }, remote).action, 'update');
  assert.equal(decide({ versionCode: 22 }, remote).action, 'current');
  assert.equal(decide({ versionCode: 23 }, remote).action, 'current');
  assert.equal(decide({ versionCode: 21 }, { ...remote, package: 'ru.tch15.arm.monitor' }).action, 'none');
  assert.equal(decide({ versionCode: 0 }, remote).action, 'none');
  assert.equal(decide({ versionCode: 21 }, null).action, 'none');
});

test('native version prefers the installed binary and skips Expo Go', () => {
  assert.deepEqual(localVersion({ nativeBuildVersion: '22', nativeAppVersion: '1.1.6', expoConfig: { version: '1.1.5', android: { versionCode: 21 } } }), { versionCode: 22, version: '1.1.6' });
  assert.equal(isStandalone({ executionEnvironment: 'bare' }), true);
  assert.equal(isStandalone({ executionEnvironment: 'storeClient' }), false);
});
