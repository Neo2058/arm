const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { buildChatPdfHtml, buildSecurePdfHtml } = require('../src/securePdfViewerHtml');

test('chat PDF html embeds pdf.js and has no postMessage download/share/print', () => {
  const html = buildChatPdfHtml('QQ==', 'https://www.xn---15-7edz.xn--p1ai');
  assert.match(html, /https:\/\/www\.xn---15-7edz\.xn--p1ai\/vendor\/pdfjs\/pdf\.min\.js/);
  assert.match(html, /https:\/\/www\.xn---15-7edz\.xn--p1ai\/vendor\/pdfjs\/pdf\.worker\.min\.js/);
  assert.match(html, /"QQ=="/);
  assert.doesNotMatch(html, /postMessage/);
  assert.doesNotMatch(html, /['"]download['"]/);
  assert.doesNotMatch(html, /['"]share['"]/);
  assert.doesNotMatch(html, /['"]print['"]/);
  assert.doesNotMatch(html, /DownloadManager/);
});

test('document viewer html still reports download/share/print', () => {
  const html = buildSecurePdfHtml('QQ==', 'https://www.xn---15-7edz.xn--p1ai');
  assert.match(html, /postMessage/);
  assert.match(html, /['"]download['"]/);
  assert.match(html, /['"]share['"]/);
  assert.match(html, /['"]print['"]/);
});

test('ChatPdfScreen fetches capability URL bytes and does not lock screenshots', () => {
  const src = fs.readFileSync(path.join(__dirname, '../src/screens/ChatPdfScreen.js'), 'utf8');
  assert.match(src, /buildChatPdfHtml/);
  assert.match(src, /fetch\(url/);
  assert.doesNotMatch(src, /expo-screen-capture/);
  assert.doesNotMatch(src, /DocumentViewScreen/);
  assert.doesNotMatch(src, /onMessage/);
  assert.doesNotMatch(src, /source=\{\{\s*uri/);
  assert.doesNotMatch(src, /Authorization/);
  assert.doesNotMatch(src, /Bearer/);
});

test('APK 1.1.0 uses versionCode 8, picker plugins, and no screen-capture plugin', () => {
  const config = fs.readFileSync(path.join(__dirname, '../app.config.js'), 'utf8');
  const api = fs.readFileSync(path.join(__dirname, '../src/api.js'), 'utf8');
  assert.match(config, /version:\s*'1\.1\.0'/);
  assert.match(config, /versionCode:\s*8/);
  assert.match(config, /expo-image-picker/);
  assert.match(config, /expo-document-picker/);
  assert.match(config, /expo-file-system/);
  assert.match(config, /ТЧ-15 использует фото, чтобы отправить снимок в рабочий чат колонны\./);
  assert.doesNotMatch(config, /expo-screen-capture/);
  assert.doesNotMatch(config, /firebase|FCM|expo-notifications/i);
  assert.match(api, /timeout:\s*25000/);
  assert.match(api, /timeout:\s*CHAT_UPLOAD_TIMEOUT_MS/);
  assert.match(api, /CHAT_UPLOAD_TIMEOUT_MS = 30000/);
  assert.doesNotMatch(api, /90000/);
});
