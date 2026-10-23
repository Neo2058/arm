const { test } = require('node:test');
const assert = require('node:assert/strict');
const {
  apiOrigin,
  arrayBufferToBase64,
  buildSecurePdfHtml,
  isAllowedViewerUrl,
} = require('../src/securePdfViewerHtml');

test('api origin is the host of the mobile API', () => {
  assert.equal(apiOrigin('https://www.xn---15-7edz.xn--p1ai/api/mobile'), 'https://www.xn---15-7edz.xn--p1ai');
});

test('viewer may load about/data/blob and same-origin pdf.js only', () => {
  const origin = 'https://www.xn---15-7edz.xn--p1ai';
  assert.equal(isAllowedViewerUrl('about:blank', origin), true);
  assert.equal(isAllowedViewerUrl('data:text/html,x', origin), true);
  assert.equal(isAllowedViewerUrl(`${origin}/vendor/pdfjs/pdf.min.js`, origin), true);
  assert.equal(isAllowedViewerUrl(`${origin}/api/mobile/files/documents/1`, origin), false);
  assert.equal(isAllowedViewerUrl('https://evil.example/x.pdf', origin), false);
});

test('html viewer embeds pdf.js from the API host and the document bytes', () => {
  const html = buildSecurePdfHtml('QQ==', 'https://www.xn---15-7edz.xn--p1ai');
  assert.match(html, /https:\/\/www\.xn---15-7edz\.xn--p1ai\/vendor\/pdfjs\/pdf\.min\.js/);
  assert.match(html, /https:\/\/www\.xn---15-7edz\.xn--p1ai\/vendor\/pdfjs\/pdf\.worker\.min\.js/);
  assert.match(html, /"QQ=="/);
  assert.doesNotMatch(html, /DownloadManager/);
});

test('array buffer is encoded as base64', () => {
  assert.equal(arrayBufferToBase64(Uint8Array.from([1, 2, 3]).buffer), 'AQID');
});
