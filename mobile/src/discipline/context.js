import { createContext, useContext, useEffect, useState } from 'react';
import { AppState, Pressable, Text, View } from 'react-native';
import * as Crypto from 'expo-crypto';
import { API_URL, client } from '../api';
import { createQueue } from './queue.cjs';
import { createStorage } from './storage';
import { submitReport } from './api';
import s from './styles';
import { createMutations } from '../journal/mutations.cjs';

const Context = createContext(null);
let current = null;
export async function clearDisciplineData() {
  const runtime = current;
  if (!runtime) return;
  runtime.controller.abort();
  await runtime.queue.stop();
  await runtime.journal.stop();
  await runtime.journalStorage.clear();
  await runtime.storage.clear();
  if (current === runtime) current = null;
}
export function DisciplineProvider({ user, children }) {
  const [runtime, setRuntime] = useState(null);
  const [error, setError] = useState('');
  const [attempt, setAttempt] = useState(0);
  useEffect(() => {
    if (user.role !== 'instructor') return;
    let disposed = false, timer, sub, local;
    (async () => {
      try {
        const storage = await createStorage(API_URL, user.id);
        const controller = new AbortController();
        const queue = createQueue({ storage, uuid: Crypto.randomUUID, send: snapshot => submitReport(snapshot, controller.signal) });
        const journalStorage = await createStorage(API_URL, user.id, 'journal-tasks');
        const journal = createMutations({ storage: journalStorage, uuid: Crypto.randomUUID, send: async request => {
          const path = request.action === 'create' ? '/journal/tasks' : `/journal/tasks/${request.id}${request.action === 'complete' ? '/complete' : ''}`;
          return (await client.request({ url: path, method: request.action === 'update' ? 'patch' : request.action === 'delete' ? 'delete' : 'post', data: request.input, signal: controller.signal })).data;
        } });
        await journal.load();
        local = { queue, storage, controller, journalStorage, journal };
        await queue.load();
        if (disposed) { await queue.stop(); return; }
        if (!user._offline) for (const [id, entry] of Object.entries(queue.snapshot().entries)) if (entry.httpStatus === 401) await queue.retry(id);
        current = local; setRuntime(local); setError('');
        const flush = () => { if (AppState.currentState === 'active') queue.flush().catch(() => setError('Не удалось сохранить состояние очереди. Отправка будет повторена с тем же ключом.')); };
        timer = setInterval(flush, 15000);
        sub = AppState.addEventListener('change', state => { if (state === 'active') flush(); });
        flush();
      } catch { if (!disposed) setError('Не удалось открыть локальные черновики. Повторите попытку; сохранённые данные не удалены.'); }
    })();
    return () => { disposed = true; clearInterval(timer); sub?.remove(); local?.controller.abort(); local?.queue.stop(); local?.journal.stop(); if (current === local) current = null; };
  }, [user.id, user.role, attempt]);
  if (user.role !== 'instructor') return children;
  if (!runtime) return <View style={s.center}><Text style={s.error}>{error || 'Загрузка сохранённых поручений…'}</Text>{!!error && <Pressable style={s.secondary} onPress={() => setAttempt(x => x + 1)}><Text style={s.buttonText}>Повторить</Text></Pressable>}</View>;
  return <Context.Provider value={runtime}>{!!error && <Text style={s.error}>{error}</Text>}{children}</Context.Provider>;
}
export function useDiscipline() {
  const runtime = useContext(Context);
  const [state, setState] = useState(() => runtime.queue.snapshot());
  useEffect(() => { setState(runtime.queue.snapshot()); return runtime.queue.subscribe(() => setState(runtime.queue.snapshot())); }, [runtime]);
  return { ...runtime, state };
}
