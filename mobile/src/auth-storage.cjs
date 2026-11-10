function isPublicAuthRequest(config) {
  const method = String(config?.method || 'get').toLowerCase();
  if (method !== 'post') return false;
  const raw = String(config?.url || '');
  let path = raw;
  if (/^https?:\/\//i.test(raw)) {
    try {
      path = new URL(raw).pathname;
    } catch {
      path = raw;
    }
  }
  return /(^|\/)login\/?$/.test(path);
}

function withTimeout(promise, ms) {
  const limit = Number(ms);
  return new Promise((resolve, reject) => {
    const timer = setTimeout(() => {
      const error = new Error('timeout');
      error.code = 'TIMEOUT';
      reject(error);
    }, Number.isFinite(limit) ? limit : 0);
    Promise.resolve(promise).then(
      (value) => {
        clearTimeout(timer);
        resolve(value);
      },
      (error) => {
        clearTimeout(timer);
        reject(error);
      },
    );
  });
}

module.exports = { isPublicAuthRequest, withTimeout };
