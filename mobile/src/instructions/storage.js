import { createStorage } from '../discipline/storage';
import { API_URL } from '../api';

const scopes = new Map();
const scopeFor = (userId) => {
  const key = `${API_URL}:${userId}`;
  if (!scopes.has(key)) scopes.set(key, { epoch: 0, writes: Promise.resolve() });
  return scopes.get(key);
};

export async function instructionsStorage(userId) {
  const scope = scopeFor(userId);
  const epoch = scope.epoch;
  const storage = await createStorage(API_URL, userId, 'instruction-signatures');
  return {
    assertActive: () => { if (scope.epoch !== epoch) throw new Error('Пользователь вышел из аккаунта.'); },
    read: () => storage.read(),
    write: (data) => {
      const write = scope.writes.then(() => {
        if (scope.epoch !== epoch) throw new Error('Пользователь вышел из аккаунта.');
        return storage.write(data);
      });
      scope.writes = write.catch(() => {});
      return write;
    },
  };
}

export async function clearInstructionsData(userId) {
  const scope = scopeFor(userId);
  scope.epoch++;
  await scope.writes;
  const storage = await createStorage(API_URL, userId, 'instruction-signatures');
  await storage.clear();
}
