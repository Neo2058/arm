const { test } = require('node:test');
const assert = require('node:assert/strict');
const {
  apiOrigin,
  arrayBufferToBase64,
  buildSecurePdfHtml,
  createPdfTransfer,
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

test('large PDF transfer uses acknowledged bounded chunks and keeps the HTML shell small', () => {
  const source = 'A'.repeat(1000000), transfer = createPdfTransfer(source), received = [];
  const vm = require('node:vm'); let last = false;
  const context = { window: { __armReceivePdf: (part, done) => { received.push(part); last = done; } } };
  for (let script; (script = transfer.next());) {
    assert.ok(script.length < 66000); vm.runInNewContext(script, context);
  }
  assert.equal(received.join(''), source); assert.equal(last, true); assert.equal(transfer.next(), null);
  const shell = buildSecurePdfHtml(null, 'https://example.test');
  assert.ok(shell.length < 15000); assert.match(shell, /viewer-ready/); assert.match(shell, /chunk-ready/);
});

test('long documents load on demand, skip stale navigation and bound canvas memory', async () => {
  const vm = require('node:vm');
  const html = buildSecurePdfHtml('QQ==', 'https://example.test');
  const ids = ['status', 'pages', 'previous', 'next', 'page-number', 'page-count', 'navigation', 'jump'];
  const elements = Object.fromEntries(ids.map(id => [id, { style: {}, appendChild(node) { this.child = node; } }]));
  const loaded = [], messages = [], canvases = [];
  let cleaned = 0;
  const pdf = { numPages: 81, getPage: async number => {
    loaded.push(number);
    return { getViewport: ({ scale }) => ({ width: 12000 * scale, height: 18000 * scale }),
      render: () => ({ promise: Promise.resolve(), cancel() {} }), cleanup: () => { cleaned++; } };
  } };
  const context = { Uint8Array, atob: text => Buffer.from(text, 'base64').toString('binary'),
    window: { innerWidth: 390, devicePixelRatio: 2, addEventListener() {}, scrollTo() {} },
    document: { getElementById: id => elements[id], createElement: () => { const c = { getContext: () => ({}) }; canvases.push(c); return c; } },
    post: (type, extra) => messages.push({ type, ...extra }),
    pdfjsLib: { GlobalWorkerOptions: {}, getDocument: () => ({ promise: Promise.resolve(pdf), destroy: () => Promise.resolve() }) },
  };
  const scripts = [...html.matchAll(/<script>([\s\S]*?)<\/script>/g)];
  context.window.pdfjsLib = context.pdfjsLib;
  vm.runInNewContext(scripts.at(-1)[1], context);
  const flush = () => new Promise(resolve => setImmediate(resolve));
  await flush(); assert.deepEqual(loaded, [1]); assert.equal(canvases.length, 1);
  elements['page-number'].value = '81'; elements.jump.onsubmit({ preventDefault() {} });
  await flush(); assert.equal(messages.at(-1).page, 81);
  for (let i = 0; i < 6; i++) elements.previous.onclick();
  await flush(); assert.deepEqual(loaded, [1, 81, 75]); assert.equal(messages.at(-1).page, 75);
  assert.ok(canvases[0].width * canvases[0].height <= 2000000);
  assert.equal(canvases.length, 1); assert.equal(cleaned, 3);
});
