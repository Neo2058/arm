const copy = (value) => JSON.parse(JSON.stringify(value));
const BACKOFF_MS = [2000, 5000, 15000];
const BACKOFF_429_MS = 30000;

function fingerprintPayload(type, body, fileSha256) {
  return `${type}\n${body}\n${fileSha256 || ''}`;
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
  let running = false;
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
    if (stopped) throw new Error('Outbox stopped');
    const next = copy(state);
    const result = await fn(next);
    await commit(next);
    return result;
  });

  api.load = async function load() {
    const saved = await storage.read();
    if (saved && saved.schema === 1 && Array.isArray(saved.items) && saved.user_id === userId) {
      state = saved;
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
  api.itemsForRoom = function itemsForRoom(roomId) {
    return state.items.filter((item) => item.room_id === roomId);
  };
  api.enqueue = async function enqueue({ room_id, type, body }) {
    const client_id = uuid();
    const fingerprint = await digest(fingerprintPayload(type, body, ''));
    await mutate((next) => {
      next.items.push({
        client_id,
        room_id,
        type,
        body,
        fingerprint,
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
      item.nextAttemptAt = 0;
    }).then(() => api.flush());
  };
  api.drop = async function drop(clientId) {
    await mutate((next) => {
      next.items = next.items.filter((row) => row.client_id !== clientId);
    });
  };
  api.flush = async function flush() {
      if (stopped || running) return;
      running = true;
      try {
        for (const item of state.items.slice()) {
          if (stopped) break;
          if (item.status !== 'pending' || (item.nextAttemptAt || 0) > now()) continue;
          const snapshot = copy(item);
          try {
            const res = await send(snapshot);
            const status = res && res.status;
            if (status === 200 || status === 201) {
              await mutate((next) => {
                next.items = next.items.filter((row) => row.client_id !== snapshot.client_id);
              });
              if (onAck) onAck(snapshot, res);
            } else if (status === 409) {
              await mutate((next) => {
                const current = next.items.find((row) => row.client_id === snapshot.client_id);
                if (!current) return;
                current.status = 'error';
                current.error = (res.data && res.data.message) || 'Этот client_id уже использован с другим содержимым.';
              });
            } else if (status === 429) {
              await mutate((next) => {
                const current = next.items.find((row) => row.client_id === snapshot.client_id);
                if (!current) return;
                current.attempts = (current.attempts || 0) + 1;
                current.nextAttemptAt = now() + BACKOFF_429_MS;
                current.error = 'нет сети';
              });
            } else if ([401, 403, 404, 410, 422].includes(status)) {
              await mutate((next) => {
                const current = next.items.find((row) => row.client_id === snapshot.client_id);
                if (!current) return;
                current.status = 'error';
                current.error = (res.data && res.data.message) || 'Сообщение не отправлено.';
              });
            } else {
              throw res && res.error ? res.error : new Error('send failed');
            }
          } catch (error) {
            const status = error && error.response && error.response.status;
            const data = error && error.response && error.response.data;
            await mutate((next) => {
              const current = next.items.find((row) => row.client_id === snapshot.client_id);
              if (!current) return;
              if (status === 409) {
                current.status = 'error';
                current.error = (data && data.message) || 'Этот client_id уже использован с другим содержимым.';
                return;
              }
              if ([401, 403, 404, 410, 422].includes(status)) {
                current.status = 'error';
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
      } finally {
        running = false;
      }
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
