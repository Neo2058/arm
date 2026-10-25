import { useCallback, useRef, useState } from 'react';
import { AppState } from 'react-native';
import { useFocusEffect } from '@react-navigation/native';
import { client } from '../api';

export function useJournal(path, collection, status = '') {
  const [data, setData] = useState(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const current = useRef(null);
  useFocusEffect(useCallback(() => {
    const controller = new AbortController();
    const request = { controller, busy: false, next: null };
    current.current = request;
    setData(null);
    const load = async (page = 1) => {
      if (request.busy || controller.signal.aborted) return;
      request.busy = true; setBusy(true);
      try {
        const response = await client.get(path, { params: { page, ...(status ? { status } : {}) }, signal: controller.signal });
        if (controller.signal.aborted) return;
        const fresh = response.data;
        if (!fresh || typeof fresh !== 'object' || (collection && !Array.isArray(fresh[collection]))) throw new Error('Invalid journal response');
        request.next = fresh.pagination?.next_page;
        setData(old => page === 1 || !old ? fresh : { ...fresh, [collection]: [...old[collection], ...fresh[collection].filter(item => !old[collection].some(x => x.id === item.id))] });
        setError('');
      } catch (e) {
        if (controller.signal.aborted) return;
        const code = e.response?.status;
        if ([401, 403].includes(code)) setData(null);
        setError(code === 401 ? 'Вход истёк. Войдите заново через профиль.' : code === 403 ? 'Журнал доступен только действующим инструкторам.' : code === 404 ? 'Мобильный журнал ещё не подключён на сервере.' : 'Не удалось обновить журнал. Проверьте соединение и повторите загрузку.');
      } finally { if (!controller.signal.aborted) { request.busy = false; setBusy(false); } }
    };
    request.load = load; load();
    const timer = setInterval(() => { if (AppState.currentState === 'active') load(); }, 30000);
    const sub = AppState.addEventListener('change', state => { if (state === 'active') load(); });
    return () => { controller.abort(); clearInterval(timer); sub.remove(); };
  }, [path, collection, status]));
  return { data, busy, error, refresh: () => current.current?.load(), more: () => { if (current.current?.next) current.current.load(current.current.next); } };
}
