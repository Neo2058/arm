const copy = (value) => JSON.parse(JSON.stringify(value));
const BACKOFF_MS = [2000, 5000, 15000];
const BACKOFF_429_MS = 30000;

function fingerprintPayload(type, body, fileSha256) {
  return `${type}\n${body || ''}\n${fileSha256 || ''}`;
}

function uploadHeld(res) {
  if (!res) return false;
  if (res.status === 202) return true;
  const remote = res.data && res.data.status;
  return res.status === 200 && remote === 'queued';
}

function createFileStorage(FS, filename) {
  const path = `${FS.documentDirectory || ''}${filename || 'chat-outbox.json'}`;
  return {
    async read() {
      try {
        const info = await FS.getInfoAsync(path);
        if (!info.exists) return null;
        return JSON.parse(await FS.readAsStringAsync(path));
      } catch {
        return null;
      }
    },
    async write(data) {
      await FS.writeAsStringAsync(path, JSON.stringify(data));
    },
    async clear() {
      await FS.deleteAsync(path, { idempotent: true }).catch(() => {});
    },
  };
}

function createOutbox({ storage, send, uuid, digest, userId, onAck, now = Date.now }) {
  let state = { schema: 1, user_id: userId, items: [] };
  let chain = Promise.resolve();
  let stopped = false;
  let flushing = false;
  let again = false;
  const listeners = new Set();
  const api = {};
  const emit = () => { for (const fn of listeners) fn(copy(state)); };
  const serial = (fn) => {
    const next = chain.then(fn);
    chain = next.catch(() => {});
    return next;
  };
  const commit = async (next) => {
    await storage.write(next);
    state = next;
    emit();
  };
  const mutate = (fn) => serial(async () => {
    if (stopped) return;
    const next = copy(state);
    const result = await fn(next);
    await commit(next);
    return result;
  });

  api.load = async function load() {
    const saved = await storage.read();
    if (saved && saved.schema === 1 && Array.isArray(saved.items) && saved.user_id === userId) {
      state = saved;
      for (const item of state.items) {
        if (item.status === 'error' && (item.httpStatus === 401 || item.httpStatus === 403)) {
          item.status = 'pending';
          item.error = '';
          item.httpStatus = undefined;
          item.nextAttemptAt = 0;
        }
      }
    } else {
      state = { schema: 1, user_id: userId, items: [] };
    }
    emit();
  };
  api.subscribe = function subscribe(fn) {
    listeners.add(fn);
    fn(copy(state));
    return () => listeners.delete(fn);
  };
  api.snapshot = function snapshot() { return copy(state); };
  api.enqueue = async function enqueue({ room_id, type, body, localUri, fileName, mime, fileSha256 }) {
    if (stopped) throw new Error('Outbox stopped');
    const client_id = uuid();
    const text = body || '';
    const fileHash = fileSha256 || '';
    const fingerprint = await digest(fingerprintPayload(type, text, fileHash));
    await mutate((next) => {
      next.items.push({
        client_id,
        room_id,
        type,
        body: text,
        fingerprint,
        fileSha256: fileHash,
        localUri: localUri || undefined,
        fileName: fileName || undefined,
        mime: mime || undefined,
        attempts: 0,
        status: 'pending',
        error: '',
        nextAttemptAt: 0,
        created_at: new Date(now()).toISOString(),
      });
    });
    await api.flush();
    return client_id;
  };
  api.retry = function retry(clientId) {
    return mutate((next) => {
      const item = next.items.find((row) => row.client_id === clientId);
      if (!item) return;
      item.status = 'pending';
      item.error = '';
      item.httpStatus = undefined;
      item.nextAttemptAt = 0;
    }).then(() => api.flush());
  };
  api.drop = async function drop(clientId) {
    await mutate((next) => {
      next.items = next.items.filter((row) => row.client_id !== clientId);
    });
  };
  api.ack = function ack(messages) {
    const done = new Set();
    for (const msg of messages || []) {
      if (msg && msg.client_id && (msg.status === 'available' || msg.status === 'rejected')) {
        done.add(msg.client_id);
      }
    }
    if (!done.size) return Promise.resolve();
    return mutate((next) => {
      next.items = next.items.filter((row) => !done.has(row.client_id));
    });
  };
  api.flush = async function flush() {
    if (stopped) return;
    if (flushing) {
      again = true;
      return;
    }
    flushing = true;
    try {
      do {
        again = false;
        for (const item of state.items.slice()) {
          if (stopped) return;
          if (item.status !== 'pending' || (item.nextAttemptAt || 0) > now()) continue;
          const snapshot = copy(item);
          try {
            const res = await send(snapshot);
            if (stopped) return;
            if (uploadHeld(res)) {
              await mutate((next) => {
                const current = next.items.find((row) => row.client_id === snapshot.client_id);
                if (!current) return;
                current.status = 'queued';
                current.httpStatus = res.status;
                current.error = '';
                current.localUri = undefined;
              });
            } else if (res && (res.status === 200 || res.status === 201)) {
              await mutate((next) => {
                next.items = next.items.filter((row) => row.client_id !== snapshot.client_id);
              });
              if (onAck) onAck(snapshot, res);
            } else {
              throw res && res.error ? res.error : new Error('send failed');
            }
          } catch (error) {
            if (stopped) return;
            const status = error && error.response && error.response.status;
            const data = error && error.response && error.response.data;
            await mutate((next) => {
              const current = next.items.find((row) => row.client_id === snapshot.client_id);
              if (!current) return;
              if (status === 409) {
                current.status = 'error';
                current.httpStatus = 409;
                current.error = (data && data.message) || 'Этот client_id уже использован с другим содержимым.';
                return;
              }
              if ([401, 403, 404, 410, 422].includes(status)) {
                current.status = 'error';
                current.httpStatus = status;
                current.error = (data && data.message) || 'Сообщение не отправлено.';
                return;
              }
              if (status === 429) {
                current.attempts = (current.attempts || 0) + 1;
                current.nextAttemptAt = now() + BACKOFF_429_MS;
                current.error = 'нет сети';
                return;
              }
              current.status = 'pending';
              current.attempts = (current.attempts || 0) + 1;
              current.nextAttemptAt = now() + BACKOFF_MS[Math.min(current.attempts - 1, BACKOFF_MS.length - 1)];
              current.error = 'нет сети';
            });
          }
        }
      } while (again && !stopped);
    } finally {
      flushing = false;
    }
    if (again && !stopped) return api.flush();
  };
  api.clear = async function clear() {
    stopped = true;
    await chain.catch(() => {});
    await storage.clear();
    state = { schema: 1, user_id: userId, items: [] };
    emit();
  };
  api.stop = async function stop() {
    stopped = true;
    await chain.catch(() => {});
  };
  return api;
}

module.exports = { fingerprintPayload, createFileStorage, createOutbox };
