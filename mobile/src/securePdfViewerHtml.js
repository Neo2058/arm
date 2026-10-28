function apiOrigin(apiUrl) {
  try {
    return new URL(apiUrl).origin;
  } catch {
    return '';
  }
}

function arrayBufferToBase64(buffer) {
  const bytes = new Uint8Array(buffer);
  const chunk = 8192;
  let binary = '';
  for (let i = 0; i < bytes.length; i += chunk) {
    binary += String.fromCharCode.apply(null, bytes.subarray(i, i + chunk));
  }
  return btoa(binary);
}

function isAllowedViewerUrl(url, origin) {
  if (!url) return false;
  const value = String(url);
  const lower = value.toLowerCase();
  if (
    lower === 'about:blank' ||
    lower.startsWith('about:srcdoc') ||
    lower.startsWith('data:') ||
    lower.startsWith('blob:')
  ) {
    return true;
  }
  if (!origin) return false;
  return value.startsWith(`${origin}/vendor/pdfjs/`);
}

function buildSecurePdfHtml(base64, origin) {
  const pdfJsSrc = JSON.stringify(`${origin}/vendor/pdfjs/pdf.min.js`);
  const payload = JSON.stringify(base64);

  return `<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=4, user-scalable=yes" />
  <style>
    html, body { margin: 0; padding: 0; background: #0b1018; color: #e5e7eb; font-family: -apple-system, sans-serif; }
    #pages { padding: 8px 0 100px; }
    canvas { display: block; margin: 8px auto; max-width: 100%; height: auto; -webkit-touch-callout: none; user-select: none; }
    #status { text-align: center; padding: 24px 16px; color: #9ca3af; }
    #status.err { color: #f87171; }
    #navigation { position: fixed; bottom: 0; left: 0; right: 0; background: #111b29; padding: 10px; display: none; align-items: center; justify-content: center; gap: 6px; flex-wrap: wrap; border-top: 1px solid #475569; }
    button, input { box-sizing: border-box; min-height: 44px; border: 1px solid #64748b; border-radius: 8px; background: #253349; color: #fff; padding: 8px; font: inherit; }
    button:disabled { opacity: .4; } input { width: 62px; text-align: center; } form { display: flex; gap: 6px; align-items: center; margin: 0; }
  </style>
</head>
<body>
  <div id="status">Загрузка документа…</div>
  <div id="pages"></div>
  <nav id="navigation" aria-label="Страницы документа">
    <button id="previous" aria-label="Предыдущая страница">←</button>
    <form id="jump"><input id="page-number" type="number" min="1" value="1" aria-label="Номер страницы" /><span id="page-count"></span><button type="submit">Перейти</button></form>
    <button id="next" aria-label="Следующая страница">→</button>
  </nav>
  <script>
    function post(type, extra) {
      try {
        window.ReactNativeWebView.postMessage(JSON.stringify(Object.assign({ type: type }, extra || {})));
      } catch (e) {}
    }
    document.addEventListener('contextmenu', function (e) { e.preventDefault(); post('download'); });
    document.addEventListener('dragstart', function (e) { e.preventDefault(); });
    document.addEventListener('copy', function (e) { e.preventDefault(); });
    document.addEventListener('keydown', function (e) {
      var key = (e.key || '').toLowerCase();
      if ((e.ctrlKey || e.metaKey) && ['s', 'p', 'u'].indexOf(key) !== -1) {
        e.preventDefault();
        post(key === 'p' ? 'print' : 'download');
      }
      if (key === 'print' || key === 'f12') e.preventDefault();
    });
    window.addEventListener('beforeprint', function (e) { e.preventDefault(); post('print'); });
    try { window.print = function () { post('print'); }; } catch (e) {}
    try { window.open = function () { post('share'); return null; }; } catch (e) {}
  </script>
  <script src=${pdfJsSrc}></script>
  <script>
    (function () {
      var status = document.getElementById('status');
      var pages = document.getElementById('pages');
      function fail(message) {
        status.className = 'err';
        status.textContent = message;
        post('error', { message: message });
      }
      if (!window.pdfjsLib) {
        fail('Не удалось загрузить средство просмотра.');
        return;
      }
      pdfjsLib.GlobalWorkerOptions.workerSrc = ${JSON.stringify(`${origin}/vendor/pdfjs/pdf.worker.min.js`)};
      function openPdf(encoded) {
      var raw;
      try {
        raw = atob(encoded);
        encoded = null;
      } catch (e) {
        fail('Документ повреждён.');
        return;
      }
      var bytes = new Uint8Array(raw.length);
      for (var i = 0; i < raw.length; i++) bytes[i] = raw.charCodeAt(i);
      raw = null;
      var loadingTask = pdfjsLib.getDocument({
        data: bytes,
        disableAutoFetch: true,
        disableStream: true,
        isEvalSupported: false
      });
      bytes = null;
      var renderTask = null;
      window.addEventListener('pagehide', function () {
        if (renderTask) renderTask.cancel();
        loadingTask.destroy().catch(function () {});
      });
      loadingTask.promise.then(function (pdf) {
        var previous = document.getElementById('previous');
        var next = document.getElementById('next');
        var input = document.getElementById('page-number');
        var canvas = document.createElement('canvas');
        pages.appendChild(canvas);
        document.getElementById('page-count').textContent = '/ ' + pdf.numPages;
        document.getElementById('navigation').style.display = 'flex';
        input.max = pdf.numPages;
        var requested = 1, generation = 0, chain = Promise.resolve();
        function showPage(number) {
          number = Math.max(1, Math.min(pdf.numPages, parseInt(number, 10) || 1));
          requested = number;
          input.value = number;
          previous.disabled = number === 1;
          next.disabled = number === pdf.numPages;
          var ticket = ++generation;
          if (renderTask) renderTask.cancel();
          status.className = '';
          status.style.display = 'block';
          status.textContent = 'Загрузка страницы ' + number + '…';
          chain = chain.catch(function () {}).then(function () {
            if (ticket !== generation) return;
            return pdf.getPage(number).then(function (page) {
              if (ticket !== generation) { page.cleanup(); return; }
              // One canvas only; also bound unusually large PDF pages to 8 MB of pixels.
              canvas.width = 0; canvas.height = 0;
              var natural = page.getViewport({ scale: 1 });
              var fit = (window.innerWidth - 16) / natural.width;
              var scale = Math.min(1.8, fit * Math.min(window.devicePixelRatio || 1, 2));
              scale = Math.min(scale, Math.sqrt(2000000 / (natural.width * natural.height)));
              var viewport = page.getViewport({ scale: scale });
              canvas.width = Math.max(1, Math.floor(viewport.width));
              canvas.height = Math.max(1, Math.floor(viewport.height));
              renderTask = page.render({ canvasContext: canvas.getContext('2d'), viewport: viewport });
              return renderTask.promise.then(function () {
                page.cleanup(); renderTask = null;
                if (ticket !== generation) return;
                status.style.display = 'none';
                window.scrollTo(0, 0);
                post('ready', { pages: pdf.numPages, page: number });
              }, function (error) {
                page.cleanup(); renderTask = null;
                if (ticket === generation) throw error;
              });
            });
          }).catch(function () {
            if (ticket === generation) fail('Не удалось отобразить страницу. Выберите её повторно или откройте другую.');
          });
        }
        previous.onclick = function () { showPage(requested - 1); };
        next.onclick = function () { showPage(requested + 1); };
        document.getElementById('jump').onsubmit = function (event) { event.preventDefault(); showPage(input.value); };
        showPage(1);
      }).catch(function () {
        fail('Не удалось открыть документ.');
      });
      }
      ${base64 === null ? `
      var chunks = [];
      window.__armReceivePdf = function (part, last) {
        chunks.push(part);
        if (last) {
          var encoded = chunks.join(''); chunks = [];
          window.__armReceivePdf = null;
          openPdf(encoded);
        } else post('chunk-ready');
      };
      post('viewer-ready');` : `openPdf(${payload});`}
    })();
  </script>
</body>
</html>`;
}

function createPdfTransfer(base64) {
  let source = base64, offset = 0;
  return { next() {
    if (!source) return null;
    const part = source.slice(offset, offset + 65536); offset += part.length;
    const last = offset >= source.length;
    if (last) source = '';
    return `window.__armReceivePdf && window.__armReceivePdf(${JSON.stringify(part)},${last});true;`;
  } };
}

module.exports = {
  __esModule: true,
  apiOrigin,
  arrayBufferToBase64,
  isAllowedViewerUrl,
  buildSecurePdfHtml,
  createPdfTransfer,
};
