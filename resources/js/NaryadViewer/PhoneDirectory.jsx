import React, { useEffect, useRef, useState } from 'react';
import { Phone, Plus, Copy } from 'lucide-react';

export default function PhoneDirectory() {
    const [rows, setRows] = useState([]);
    const [query, setQuery] = useState('');
    const [name, setName] = useState('');
    const [phone, setPhone] = useState('');
    const [page, setPage] = useState(1);
    const [more, setMore] = useState(false);
    const [busy, setBusy] = useState(false);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');
    const [notice, setNotice] = useState('');
    const [legacy, setLegacy] = useState(null);
    const sequence = useRef(0);
    const saving = useRef(false);

    async function load(q = query, next = 1) {
        const id = ++sequence.current;
        setLoading(true);
        setError('');
        try {
            const response = await fetch(`/api/phones?${new URLSearchParams({ q: q.trim(), page: next })}`, { headers: { Accept: 'application/json' } });
            if (!response.ok) throw new Error('Не удалось загрузить справочник. Проверьте вход и сеть.');
            const data = await response.json();
            if (id !== sequence.current) return;
            setRows(prev => next === 1 ? data.phones : [...prev, ...data.phones]);
            setPage(next); setMore(data.has_more);
        } catch (e) { if (id === sequence.current) setError(e.message); }
        finally { if (id === sequence.current) setLoading(false); }
    }

    useEffect(() => {
        sequence.current++;
        const timer = setTimeout(() => load(query), 300);
        return () => { clearTimeout(timer); sequence.current++; };
    }, [query]);

    async function add(event) {
        event?.preventDefault();
        if (saving.current) return;
        saving.current = true; setBusy(true); setError(''); setNotice('');
        try {
            const response = await fetch('/api/phones', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
                body: JSON.stringify({ name, phone }),
            });
            const data = await response.json();
            if (!response.ok) throw new Error(Object.values(data.errors || {}).flat()[0] || (response.status === 429 ? 'Слишком много добавлений. Подождите минуту.' : 'Не удалось сохранить. Проверьте вход и сеть.'));
            setName(''); setPhone(''); setNotice('Контакт сохранён в общем справочнике.');
            setQuery(''); await load('');
        } catch (e) { setError(e.message || 'Не удалось сохранить. Поля сохранены для повторной попытки.'); }
        finally { saving.current = false; setBusy(false); }
    }

    function showLegacy() {
        try {
            const saved = JSON.parse(localStorage.getItem('naryad_phones') || '[]');
            setLegacy(Array.isArray(saved) ? saved.filter(p => typeof p?.fio === 'string' && typeof p?.phone === 'string') : []);
        } catch { setLegacy([]); }
    }

    const field = 'w-full rounded-2xl bg-[#0b1018] border border-white/20 px-4 py-3 text-orange-100';
    return <div className="space-y-4">
        <div className="bg-white/5 border border-white/10 rounded-3xl p-4 space-y-3">
            <h2 className="flex gap-2 text-orange-400 font-semibold"><Phone size={18} /> Общий справочник телефонов</h2>
            <p className="text-sm text-orange-100/70">Добавленные ФИО и номер будут видны всем пользователям веба и приложения.</p>
            <input className={field} aria-label="Поиск контактов" placeholder="Поиск по ФИО или номеру" value={query} onChange={e => setQuery(e.target.value)} maxLength={160} />
            <form onSubmit={add} className="space-y-3">
                <input className={field} aria-label="ФИО" placeholder="ФИО машиниста" value={name} onChange={e => setName(e.target.value)} maxLength={160} required disabled={busy} />
                <input className={field} aria-label="Телефон" placeholder="+7 (999) 123-45-67" type="tel" value={phone} onChange={e => setPhone(e.target.value)} maxLength={40} required disabled={busy} />
                <button disabled={busy} className="flex items-center justify-center gap-2 w-full rounded-2xl bg-orange-500 text-white py-3 disabled:opacity-50"><Plus size={20} />{busy ? 'Сохраняем…' : 'Добавить телефон'}</button>
            </form>
            {error && <p role="alert" className="text-red-300">{error}</p>}
            {notice && <p role="status" className="text-green-300">{notice}</p>}
            <button onClick={() => load()} disabled={loading} className="text-orange-300 underline">Обновить справочник</button>
        </div>
        {loading && <p role="status" className="text-orange-200">Загрузка…</p>}
        {!loading && rows.length === 0 && <p className="text-orange-200">Контакты не найдены</p>}
        {rows.map(p => <div key={p.id} className="flex gap-3 items-center bg-white/5 rounded-3xl p-4">
            <div className="flex-1 min-w-0"><div className="text-orange-100 font-semibold break-words">{p.name}</div><a className="text-orange-400 text-xl" href={`tel:${p.phone}`}>{p.phone}</a></div>
            <button aria-label="Скопировать номер" className="p-3 text-orange-200" onClick={() => navigator.clipboard?.writeText(p.phone).catch(() => setError('Не удалось скопировать номер.'))}><Copy size={19} /></button>
        </div>)}
        {more && <button className="p-3 text-orange-300" disabled={loading} onClick={() => load(query, page + 1)}>Показать ещё</button>}
        <div className="text-orange-200 text-sm space-y-2">
            <button className="underline" onClick={showLegacy}>Посмотреть прежние контакты этого браузера</button>
            {legacy && <p>Проверьте каждый контакт: прежний список мог содержать примеры. Выбор заполняет форму; сохранение выполняется отдельно. Локальные данные не удаляются.</p>}
            {legacy?.length === 0 && <p>Локальных контактов нет.</p>}
            {legacy?.map((p, i) => <button key={i} className="block p-2 border border-white/10 rounded-xl" disabled={busy} onClick={() => { setName(p.fio); setPhone(p.phone); }}>{p.fio} — {p.phone}: заполнить форму</button>)}
        </div>
    </div>;
}
