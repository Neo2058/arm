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
    #pages { padding: 8px 0 24px; }
    canvas { display: block; margin: 8px auto; max-width: 100%; height: auto; -webkit-touch-callout: none; user-select: none; }
    #status { text-align: center; padding: 24px 16px; color: #9ca3af; }
    #status.err { color: #f87171; }
  </style>
</head>
<body>
  <div id="status">Загрузка документа…</div>
  <div id="pages"></div>
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
      var raw;
      try {
        raw = atob(${payload});
      } catch (e) {
        fail('Документ повреждён.');
        return;
      }
      var bytes = new Uint8Array(raw.length);
      for (var i = 0; i < raw.length; i++) bytes[i] = raw.charCodeAt(i);
      pdfjsLib.getDocument({
        data: bytes,
        disableAutoFetch: true,
        disableStream: true,
        isEvalSupported: false
      }).promise.then(function (pdf) {
        status.style.display = 'none';
        var chain = Promise.resolve();
        for (var n = 1; n <= pdf.numPages; n++) {
          (function (pageNumber) {
            chain = chain.then(function () {
              return pdf.getPage(pageNumber).then(function (page) {
                var scale = 1.4;
                var viewport = page.getViewport({ scale: scale });
                var canvas = document.createElement('canvas');
                canvas.width = viewport.width;
                canvas.height = viewport.height;
                pages.appendChild(canvas);
                return page.render({ canvasContext: canvas.getContext('2d'), viewport: viewport }).promise;
              });
            });
          })(n);
        }
        return chain.then(function () { post('ready', { pages: pdf.numPages }); });
      }).catch(function () {
        fail('Не удалось открыть документ.');
      });
    })();
  </script>
</body>
</html>`;
}

module.exports = {
  __esModule: true,
  apiOrigin,
  arrayBufferToBase64,
  isAllowedViewerUrl,
  buildSecurePdfHtml,
};
