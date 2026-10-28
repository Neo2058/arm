import { absenceDraft, createEditor, currentMonth, errorText, requestFor, settingsDraft, shiftDraft } from './model.mjs';

// React is supplied by each platform so Vite never bundles mobile's React copy.
export function createUseWorkTime({ useEffect, useRef, useState }) {
  return function useWorkTime({ scope, makeStorage, request, uuid }) {
    const [month, setMonth] = useState(currentMonth);
    const [summary, setSummary] = useState(null), [settings, setSettings] = useState(null);
    const [editor, setEditor] = useState(null), [entry, setEntry] = useState(null);
    const [busy, setBusy] = useState(false), [error, setError] = useState(''), [preview, setPreview] = useState(null);
    const active = useRef(false), generation = useRef(0), loadGeneration = useRef(0);
    const adapters = useRef({ makeStorage, request, uuid }); adapters.current = { makeStorage, request, uuid };
    const monthRef = useRef(month); monthRef.current = month;
    const reload = async () => {
      const gen = generation.current, load = ++loadGeneration.current, selected = monthRef.current;
      const api = adapters.current.request;
      const [data, config] = await Promise.all([api({ method: 'get', url: '/month', params: { month: selected } }), api({ method: 'get', url: '/settings', params: { month: selected } })]);
      if (gen === generation.current && load === loadGeneration.current && selected === monthRef.current) { setSummary(data); setSettings(config); }
    };
    useEffect(() => {
      const gen = ++generation.current;
      const abort = new AbortController();
      let local, unsubscribe;
      setEditor(null); setEntry(null); setSummary(null); setSettings(null); setError('');
      (async () => {
        const storage = await adapters.current.makeStorage();
        if (gen !== generation.current) return;
        local = createEditor({ storage, uuid: () => adapters.current.uuid(), send: op => {
          storage.assertActive?.();
          if (gen !== generation.current) throw new Error('Редактор закрыт.');
          return adapters.current.request({ ...op, signal: abort.signal });
        } });
        await local.load();
        if (gen !== generation.current) { await local.stop(); return; }
        unsubscribe = local.subscribe(() => { if (gen === generation.current) setEntry(local.snapshot()); });
        setEntry(local.snapshot()); setEditor(local);
      })().catch(() => { if (gen === generation.current) setError('Не удалось открыть сохранённые запросы. Перезагрузите экран; данные не удалены.'); });
      return () => { generation.current++; abort.abort(); unsubscribe?.(); local?.stop(); };
    }, [scope]);
    const run = async fn => {
      if (active.current) return;
      const gen = generation.current;
      active.current = true; setBusy(true); setError('');
      try { await fn(); } catch (e) { if (gen === generation.current) setError(e.response ? errorText(e) : e.message || 'Не удалось выполнить действие.'); }
      finally { active.current = false; if (gen === generation.current) setBusy(false); }
    };
    useEffect(() => { setSummary(null); setSettings(null); run(reload); }, [month, scope]);
    return {
      month, setMonth, summary, settings, entry, busy, error, preview, ready: !!editor,
      reload: () => run(reload),
      openShift: id => run(async () => { const shift = id ? (await adapters.current.request({ method: 'get', url: `/shifts/${id}` })).shift : null; await editor.open(shiftDraft(shift, `${month}-01`)); setPreview(null); }),
      openDate: date => run(async () => { await editor.open(shiftDraft(null, date)); setPreview(null); }),
      openAbsence: () => run(async () => { await editor.open(absenceDraft(null, `${month}-01`)); setPreview(null); }),
      openSettings: () => run(async () => { const fresh = await adapters.current.request({ method: 'get', url: '/settings', params: { month } }); await editor.open(settingsDraft(fresh)); setPreview(null); }),
      edit: patch => { setPreview(null); return editor.edit(patch).catch(() => setError('Не удалось сохранить ввод на устройстве. Повторите изменение перед отправкой.')); },
      close: () => run(async () => { await editor.close(); setPreview(null); }),
      submit: values => run(async () => { await editor.edit(values); await editor.submit(); if (editor.snapshot().result) await reload(); }),
      retry: () => run(async () => { await editor.retry(); if (editor.snapshot().result) await reload(); }),
      unlock: () => run(async () => { await editor.unlock(); setPreview(null); }),
      previewShift: values => run(async () => {
        await editor.edit(values);
        const op = requestFor(editor.snapshot().draft);
        const data = await adapters.current.request({ method: 'post', url: `${op.url}/preview`, data: op.data });
        setPreview(data.shift.calculation_result);
      }),
      loadConflict: () => run(async () => {
        const s = editor.snapshot(), target = s.draft.target;
        const data = await adapters.current.request(s.draft.kind === 'settings' ? { method: 'get', url: '/settings', params: { month: target.month } } : { method: 'get', url: `/shifts/${target.id}` });
        await editor.setCurrent(data);
      }),
    };
  };
}
