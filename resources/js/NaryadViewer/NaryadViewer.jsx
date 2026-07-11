import React, { useState, useMemo, useEffect, useRef } from 'react';
import { Search, Eye, Phone, BookOpen, ChevronLeft, ChevronRight, ZoomIn, ZoomOut, Plus, Trash2, Copy, X } from 'lucide-react';

export default function NaryadViewer({ naryads = [], isAdmin = false }) {
    // Read initial tab from query for sidebar deep links
    const getInitialTab = () => {
        if (typeof window !== 'undefined') {
            const p = new URLSearchParams(window.location.search).get('tab');
            if (['naryady', 'phones', 'explanations'].includes(p)) return p;
        }
        return 'naryady';
    };

    const [activeTab, setActiveTab] = useState(getInitialTab);

    const [search, setSearch] = useState('');
    const [dateFrom, setDateFrom] = useState('');
    const [dateTo, setDateTo] = useState('');
    const [selected, setSelected] = useState(null);
    const [pdfUrl, setPdfUrl] = useState(null);

    // Advanced PDF.js state
    const [pdfDoc, setPdfDoc] = useState(null);
    const [currentPage, setCurrentPage] = useState(1);
    const [numPages, setNumPages] = useState(0);
    const [scale, setScale] = useState(1.5);
    const [pdfSearchTerm, setPdfSearchTerm] = useState('');
    const [searchMatches, setSearchMatches] = useState([]);
    const [currentMatchIndex, setCurrentMatchIndex] = useState(0);
    const canvasRef = useRef(null);
    const pdfContainerRef = useRef(null);
    const touchStartX = useRef(0);

    // Phone directory - full FIO search + add
    const [phones, setPhones] = useState(() => {
        const saved = localStorage.getItem('naryad_phones');
        return saved ? JSON.parse(saved) : [
            { id: 1, fio: 'Иванов Иван Иванович', phone: '+7 (999) 123-45-67' },
            { id: 2, fio: 'Петров Петр Петрович', phone: '+7 (999) 234-56-78' },
            { id: 3, fio: 'Сидоров Сидор Сидорович', phone: '+7 (999) 345-67-89' },
            { id: 4, fio: 'Кузнецов Алексей Сергеевич', phone: '+7 (912) 555-12-34' },
        ];
    });
    const [phoneSearch, setPhoneSearch] = useState('');
    const [newPhoneFio, setNewPhoneFio] = useState('');
    const [newPhoneNum, setNewPhoneNum] = useState('');

    // Log phone searches (debounced)
    useEffect(() => {
        if (!phoneSearch || phoneSearch.length < 2) return;
        const t = setTimeout(() => {
            logAction('search_phones', { query: phoneSearch });
        }, 800);
        return () => clearTimeout(t);
    }, [phoneSearch]);

    // Shift explanations / расшифровки смен - full add + read + search
    const [explanations, setExplanations] = useState(() => {
        const saved = localStorage.getItem('naryad_explanations');
        return saved ? JSON.parse(saved) : [
            { id: 1, shift: '12 (любой) [3+-ранняя ночь]', text: 'Смена начинается в 16:20, заканчивается в 00:06 следующего дня. Перерыв на обед 40 мин.', date: '2026-06-01' },
            { id: 2, shift: '5 (чётная) [ночь]', text: 'Ночная смена. Выход на линию с 23:50. С утра подменяет дневную бригаду.', date: '2026-06-02' },
        ];
    });
    const [expSearch, setExpSearch] = useState('');
    const [newExpShift, setNewExpShift] = useState('');
    const [newExpText, setNewExpText] = useState('');

    // === Новый функционал: Пакетный поиск по именам (аналог старой C++ программы) ===
    const [searchLists, setSearchLists] = useState(() => {
        const saved = localStorage.getItem('naryad_search_lists');
        return saved ? JSON.parse(saved) : [];
    });
    const [newSearchListName, setNewSearchListName] = useState('');
    const [newQueryForList, setNewQueryForList] = useState('');
    const [editingListId, setEditingListId] = useState(null);
    const [selectedSearchListIds, setSelectedSearchListIds] = useState([]);
    const [selectedNaryadIdsForSearch, setSelectedNaryadIdsForSearch] = useState([]);
    const [batchSearchResults, setBatchSearchResults] = useState([]);
    const [isBatchSearching, setIsBatchSearching] = useState(false);
    const [pdfTextCache, setPdfTextCache] = useState({}); // cache extracted text {naryadId: [{page, text}, ...]}

    // Auto-clear batch results when selections change
    useEffect(() => {
        if (batchSearchResults.length > 0) setBatchSearchResults([]);
    }, [selectedSearchListIds, selectedNaryadIdsForSearch]);

    // Log explanations searches (debounced)
    useEffect(() => {
        if (!expSearch || expSearch.length < 2) return;
        const t = setTimeout(() => {
            logAction('search_explanations', { query: expSearch });
        }, 800);
        return () => clearTimeout(t);
    }, [expSearch]);

    // Persist search lists
    useEffect(() => {
        localStorage.setItem('naryad_search_lists', JSON.stringify(searchLists));
    }, [searchLists]);

    // Logging helper for user activity (phones / naryads / explanations)
    const logAction = (action, details = {}) => {
        if (typeof window === 'undefined') return;
        const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        fetch('/api/actions/log', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': token,
            },
            body: JSON.stringify({ action, details }),
        }).catch(() => {}); // fire-and-forget
    };

    // Log when user switches between the three main blocks
    useEffect(() => {
        const map = {
            naryady: 'view_naryads_tab',
            phones: 'view_phones_tab',
            explanations: 'view_explanations_tab',
        };
        const act = map[activeTab];
        if (act) logAction(act);
    }, [activeTab]);

    // Persist phones
    useEffect(() => {
        localStorage.setItem('naryad_phones', JSON.stringify(phones));
    }, [phones]);

    // Persist explanations
    useEffect(() => {
        localStorage.setItem('naryad_explanations', JSON.stringify(explanations));
    }, [explanations]);

    const filteredNaryads = useMemo(() => {
        const parseDate = (d) => {
            if (!d) return null;
            const parts = d.includes('.') ? d.split('.') : d.split('-');
            if (parts.length === 3) {
                if (parts[0].length === 4) return new Date(d);
                return new Date(`${parts[2]}-${parts[1]}-${parts[0]}`);
            }
            return new Date(d);
        };
        const from = dateFrom ? new Date(dateFrom) : null;
        const to = dateTo ? new Date(dateTo) : null;
        return naryads.filter(n => {
            const titleMatch = !search.trim() || (n.title || '').toLowerCase().includes(search.toLowerCase());
            const nDate = parseDate(n.naryad_date_iso || n.naryad_date);
            const dateMatch = (!from || !nDate || nDate >= from) && (!to || !nDate || nDate <= to);
            return titleMatch && dateMatch;
        });
    }, [naryads, search, dateFrom, dateTo]);

    // Phones filtered by FIO (full search)
    const filteredPhones = useMemo(() => {
        const q = phoneSearch.trim().toLowerCase();
        if (!q) return phones;
        return phones.filter(p => (p.fio || '').toLowerCase().includes(q));
    }, [phones, phoneSearch]);

    // Explanations filtered by shift or text
    const filteredExps = useMemo(() => {
        const q = expSearch.trim().toLowerCase();
        if (!q) return explanations;
        return explanations.filter(e =>
            (e.shift || '').toLowerCase().includes(q) ||
            (e.text || '').toLowerCase().includes(q)
        );
    }, [explanations, expSearch]);

    const openNaryad = async (naryad) => {
        setSelected(naryad);
        setPdfSearchTerm('');
        setSearchMatches([]);
        setCurrentPage(1);
        setPdfDoc(null);
        setScale(1.6);

        // Log opening a specific naryad PDF
        logAction('open_naryad', { id: naryad.id, title: naryad.title, date: naryad.naryad_date });

        try {
            const res = await fetch(`/naryady/${naryad.id}`);
            const data = await res.json();
            setPdfUrl(data.url);
        } catch (e) {
            setPdfUrl(naryad.url || '');
        }
    };

    const close = () => {
        setSelected(null);
        setPdfUrl(null);
        setPdfSearchTerm('');
        setSearchMatches([]);
        setPdfDoc(null);
    };

    // Tab switcher (support mobile + sidebar deep links)
    const switchTab = (tab) => {
        setActiveTab(tab);
        // update url without reload
        if (typeof window !== 'undefined') {
            const url = new URL(window.location);
            url.searchParams.set('tab', tab);
            window.history.replaceState({}, '', url);
        }
    };

    // Phones handlers - full featured
    const addPhone = () => {
        if (!isAdmin) return;
        const fio = newPhoneFio.trim();
        const phone = newPhoneNum.trim();
        if (!fio || !phone) return;
        const newEntry = {
            id: Date.now(),
            fio,
            phone,
        };
        setPhones(prev => [newEntry, ...prev]);
        setNewPhoneFio('');
        setNewPhoneNum('');

        logAction('add_phone', { fio });
    };

    const deletePhone = (id) => {
        if (!isAdmin) return;
        const entry = phones.find(p => p.id === id);
        setPhones(prev => prev.filter(p => p.id !== id));
        if (entry) logAction('delete_phone', { fio: entry.fio });
    };

    const copyPhone = async (phone) => {
        try {
            await navigator.clipboard.writeText(phone);
            // simple visual feedback via alert for mobile simplicity
            alert('Телефон скопирован: ' + phone);
        } catch (_) {
            // fallback
            prompt('Скопируйте телефон:', phone);
        }
    };

    // Explanations (расшифровки смен) handlers
    const addExplanation = () => {
        if (!isAdmin) return;
        const shift = newExpShift.trim();
        const text = newExpText.trim();
        if (!shift || !text) return;
        const newEntry = {
            id: Date.now(),
            shift,
            text,
            date: new Date().toISOString().slice(0, 10),
        };
        setExplanations(prev => [newEntry, ...prev]);
        setNewExpShift('');
        setNewExpText('');

        logAction('add_explanation', { shift });
    };

    const deleteExplanation = (id) => {
        if (!isAdmin) return;
        const entry = explanations.find(e => e.id === id);
        setExplanations(prev => prev.filter(e => e.id !== id));
        if (entry) logAction('delete_explanation', { shift: entry.shift });
    };

    // === Search lists handlers (mimics old C++ "search files") ===
    const addSearchList = () => {
        const name = newSearchListName.trim();
        if (!name) return;
        const newList = { id: Date.now(), name, queries: [] };
        setSearchLists(prev => [...prev, newList]);
        setNewSearchListName('');
        setEditingListId(newList.id);
    };

    const deleteSearchList = (id) => {
        setSearchLists(prev => prev.filter(l => l.id !== id));
        setSelectedSearchListIds(prev => prev.filter(s => s !== id));
        if (editingListId === id) setEditingListId(null);
    };

    const addQueryToList = (listId) => {
        const q = newQueryForList.trim();
        if (!q) return;
        setSearchLists(prev => prev.map(l => {
            if (l.id !== listId) return l;
            if (l.queries.includes(q)) return l;
            return { ...l, queries: [...l.queries, q] };
        }));
        setNewQueryForList('');
    };

    const removeQueryFromList = (listId, q) => {
        setSearchLists(prev => prev.map(l => {
            if (l.id !== listId) return l;
            return { ...l, queries: l.queries.filter(x => x !== q) };
        }));
    };

    const toggleSearchList = (id) => {
        setSelectedSearchListIds(prev =>
            prev.includes(id) ? prev.filter(x => x !== id) : [...prev, id]
        );
    };

    const toggleNaryadForSearch = (id) => {
        setSelectedNaryadIdsForSearch(prev =>
            prev.includes(id) ? prev.filter(x => x !== id) : [...prev, id]
        );
    };

    // Extract full text from a naryad PDF (client side)
    const getNaryadText = async (naryad) => {
        if (pdfTextCache[naryad.id]) return pdfTextCache[naryad.id];

        try {
            let url = naryad.url;
            if (!url) {
                const res = await fetch(`/naryady/${naryad.id}`);
                const data = await res.json();
                url = data.url;
            }
            if (!window.pdfjsLib) {
                await new Promise((res, rej) => {
                    const s = document.createElement('script');
                    s.src = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js';
                    s.onload = () => {
                        window.pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
                        res();
                    };
                    s.onerror = rej;
                    document.head.appendChild(s);
                });
            }
            const pdfjs = window.pdfjsLib;
            const pdf = await pdfjs.getDocument(url).promise;
            const pages = [];
            for (let p = 1; p <= pdf.numPages; p++) {
                const page = await pdf.getPage(p);
                const content = await page.getTextContent();
                const text = content.items.map(it => it.str).join(' ');
                pages.push({ page: p, text });
            }
            const result = { title: naryad.title, date: naryad.naryad_date, pages };
            setPdfTextCache(prev => ({ ...prev, [naryad.id]: result }));
            return result;
        } catch (e) {
            console.error('Text extract failed', e);
            return null;
        }
    };

    // Main batch search - mimics the C++ logic
    const runBatchSearch = async () => {
        const lists = searchLists.filter(l => selectedSearchListIds.includes(l.id));
        const naryadsToSearch = filteredNaryads.filter(n => selectedNaryadIdsForSearch.includes(n.id));

        if (lists.length === 0 || naryadsToSearch.length === 0) {
            alert('Выберите хотя бы один список поиска и хотя бы один наряд');
            return;
        }

        setIsBatchSearching(true);
        const results = [];

        for (const naryad of naryadsToSearch) {
            const textData = await getNaryadText(naryad);
            if (!textData) {
                results.push({ naryadTitle: naryad.title, error: 'Не удалось извлечь текст' });
                continue;
            }

            for (const list of lists) {
                for (const query of list.queries) {
                    let foundAny = false;
                    textData.pages.forEach(pt => {
                        const lower = pt.text.toLowerCase();
                        const qlower = query.toLowerCase();
                        if (lower.includes(qlower)) {
                            foundAny = true;
                            // simple context snippet
                            const idx = lower.indexOf(qlower);
                            const start = Math.max(0, idx - 40);
                            const end = Math.min(pt.text.length, idx + query.length + 40);
                            let snippet = pt.text.substring(start, end);
                            snippet = snippet.replace(new RegExp(query, 'gi'), '***$&***');
                            results.push({
                                naryadTitle: naryad.title,
                                date: naryad.naryad_date,
                                listName: list.name,
                                query,
                                page: pt.page,
                                snippet
                            });
                        }
                    });
                    if (!foundAny) {
                        results.push({
                            naryadTitle: naryad.title,
                            date: naryad.naryad_date,
                            listName: list.name,
                            query,
                            notFound: true
                        });
                    }
                }
            }
        }

        setBatchSearchResults(results);
        setIsBatchSearching(false);
        logAction('batch_naryad_search', { lists: lists.length, naryads: naryadsToSearch.length });
    };

    // Load pdf.js from CDN + PDF (mobile friendly init)
    useEffect(() => {
        if (!pdfUrl || !selected) return;
        let cancelled = false;
        (async () => {
            try {
                if (!window.pdfjsLib) {
                    await new Promise((res, rej) => {
                        const s = document.createElement('script');
                        s.src = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js';
                        s.onload = () => {
                            window.pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
                            res();
                        };
                        s.onerror = rej;
                        document.head.appendChild(s);
                    });
                }
                const pdfjs = window.pdfjsLib;
                const pdf = await pdfjs.getDocument(pdfUrl).promise;
                if (!cancelled) {
                    setPdfDoc(pdf);
                    setNumPages(pdf.numPages);
                    setCurrentPage(1);
                }
            } catch (e) {
                console.warn('pdf.js failed, using iframe fallback');
            }
        })();
        return () => { cancelled = true; };
    }, [pdfUrl, selected]);

    // Render current page + highlight support (mobile touch ready)
    useEffect(() => {
        if (!pdfDoc || !canvasRef.current) return;
        (async () => {
            const page = await pdfDoc.getPage(currentPage);
            const viewport = page.getViewport({ scale });
            const canvas = canvasRef.current;
            const ctx = canvas.getContext('2d', { alpha: true });
            canvas.width = viewport.width;
            canvas.height = viewport.height;

            await page.render({ canvasContext: ctx, viewport }).promise;

            const textContent = await page.getTextContent();
            window.__pdfItems = textContent.items;
            window.__pdfViewport = viewport;
            window.__pdfCtx = ctx;

            // Re-apply highlight after fresh render if searching
            if (pdfSearchTerm && searchMatches.some(m => m.page === currentPage)) {
                // small delay to ensure canvas painted
                setTimeout(() => highlightOnCanvas(pdfSearchTerm), 30);
            }
        })();
    }, [pdfDoc, currentPage, scale, pdfSearchTerm]);

    // Touch swipe support for PDF pages (mobile) + pinch zoom
    useEffect(() => {
        const el = pdfContainerRef.current;
        if (!el) return;

        let initialDistance = 0;
        let initialScaleOnPinch = 1;

        const getDistance = (t1, t2) => {
            const dx = t1.clientX - t2.clientX;
            const dy = t1.clientY - t2.clientY;
            return Math.sqrt(dx * dx + dy * dy);
        };

        const onTouchStart = (e) => {
            touchStartX.current = e.touches[0].clientX;
            if (e.touches.length === 2) {
                initialDistance = getDistance(e.touches[0], e.touches[1]);
                initialScaleOnPinch = scale;
            }
        };

        const onTouchMove = (e) => {
            if (e.touches.length === 2) {
                // Pinch zoom
                e.preventDefault();
                const distance = getDistance(e.touches[0], e.touches[1]);
                if (initialDistance > 0) {
                    const newScale = Math.max(0.5, Math.min(4, initialScaleOnPinch * (distance / initialDistance)));
                    setScale(newScale);
                }
            } else if (e.touches.length === 1) {
                // Horizontal swipe intent - prevent page scroll
                const currentX = e.touches[0].clientX;
                if (Math.abs(currentX - touchStartX.current) > 15) {
                    e.preventDefault();
                }
            }
        };

        const onTouchEnd = (e) => {
            if (e.touches.length > 0) return; // still pinching or multi
            if (!pdfDoc) return;

            const endX = e.changedTouches[0].clientX;
            const delta = endX - touchStartX.current;

            // Only swipe if not in middle of pinch (small delta after pinch may remain)
            if (Math.abs(delta) > 60) {
                if (delta > 0) changePage(-1);
                else changePage(1);
            }
        };

        el.addEventListener('touchstart', onTouchStart, { passive: true });
        el.addEventListener('touchmove', onTouchMove, { passive: false });
        el.addEventListener('touchend', onTouchEnd, { passive: true });

        return () => {
            el.removeEventListener('touchstart', onTouchStart);
            el.removeEventListener('touchmove', onTouchMove);
            el.removeEventListener('touchend', onTouchEnd);
        };
    }, [pdfDoc, currentPage, scale]);

    const highlightOnCanvas = (term) => {
        const ctx = window.__pdfCtx;
        const items = window.__pdfItems;
        const v = window.__pdfViewport;
        if (!ctx || !items || !v || !term) return;

        const pdfjs = window.pdfjsLib;
        if (!pdfjs || !pdfjs.Util) return;

        ctx.save();
        ctx.fillStyle = 'rgba(250, 204, 21, 0.6)';
        items.forEach(item => {
            if ((item.str || '').toLowerCase().includes(term.toLowerCase())) {
                const tx = pdfjs.Util.transform(v.transform, item.transform);
                const x = tx[4];
                const y = tx[5] - (item.height || 10) * (v.scale || 1) * 0.9;
                const w = (item.width || 60) * (v.scale || 1);
                const h = (item.height || 10) * (v.scale || 1) * 1.1;
                ctx.fillRect(x, y, w, h);
            }
        });
        ctx.restore();
    };

    const performAdvancedSearch = async (term) => {
        if (!term || !pdfDoc) {
            setSearchMatches([]);
            return;
        }

        // Log PDF text search
        logAction('search_naryad_pdf', { term, naryad: selected?.title });
        const lower = term.toLowerCase();
        const matches = [];
        for (let p = 1; p <= numPages; p++) {
            const page = await pdfDoc.getPage(p);
            const txt = (await page.getTextContent()).items.map(i => i.str || '').join(' ').toLowerCase();
            if (txt.includes(lower)) {
                matches.push({ page: p, snippet: term });
            }
        }
        setSearchMatches(matches);
        setCurrentMatchIndex(0);
        if (matches.length > 0) {
            const first = matches[0];
            if (first.page !== currentPage) {
                setCurrentPage(first.page);
            } else {
                setTimeout(() => highlightOnCanvas(term), 50);
            }
        }
    };

    const goToMatch = (i) => {
        if (!searchMatches.length) return;
        const m = searchMatches[i];
        setCurrentMatchIndex(i);
        if (m.page !== currentPage) {
            setCurrentPage(m.page);
        } else {
            highlightOnCanvas(pdfSearchTerm);
        }
    };

    const changePage = (d) => setCurrentPage(p => Math.max(1, Math.min(numPages, p + d)));
    const changeScale = (d) => setScale(s => Math.max(0.7, Math.min(3.5, s + d)));

    // Keyboard shortcuts (harmless on mobile)
    useEffect(() => {
        const h = (e) => { if ((e.ctrlKey || e.metaKey) && ['s','p','u'].includes(e.key.toLowerCase())) e.preventDefault(); };
        document.addEventListener('keydown', h);
        return () => document.removeEventListener('keydown', h);
    }, []);

    // Mobile-first tab bar + 3 full blocks
    return (
        <div className="max-w-5xl mx-auto pb-16">
            {/* Big mobile-first header — centered + offset for fixed burger on small screens */}
            <div className="mb-3 px-1 pt-10 sm:pt-1 text-center sm:text-left">
                <h1 className="text-2xl sm:text-3xl font-bold text-orange-400 tracking-tight">Наряды и справочники</h1>
                <p className="text-sm text-orange-300 mt-0.5">Мобильная версия • три блока</p>
            </div>

            {/* Sticky large-tab bar optimized for thumbs (tight for 390px screens) */}
            <div className="sticky top-0 z-50 bg-[#0a0a0a]/95 backdrop-blur border-b border-white/10 mb-4 -mx-0.5 px-0.5 py-1.5">
                <div className="flex gap-1 rounded-2xl bg-zinc-900/70 p-0.5">
                    <button
                        onClick={() => switchTab('naryady')}
                        className={`flex-1 flex items-center justify-center gap-1 py-2 px-1.5 sm:px-3 rounded-2xl text-xs sm:text-sm font-semibold transition active:scale-[0.985] min-h-[48px] whitespace-nowrap ${activeTab === 'naryady' ? 'bg-orange-500 text-white shadow' : 'bg-white/5 text-orange-300 hover:bg-white/10'}`}
                    >
                        <Eye size={15} /> <span className="hidden sm:inline">Наряды</span><span className="sm:hidden">Нар.</span>
                    </button>
                    <button
                        onClick={() => switchTab('phones')}
                        className={`flex-1 flex items-center justify-center gap-1 py-2 px-1.5 sm:px-3 rounded-2xl text-xs sm:text-sm font-semibold transition active:scale-[0.985] min-h-[48px] whitespace-nowrap ${activeTab === 'phones' ? 'bg-orange-500 text-white shadow' : 'bg-white/5 text-orange-300 hover:bg-white/10'}`}
                    >
                        <Phone size={15} /> <span className="hidden sm:inline">Телефоны</span><span className="sm:hidden">Тел.</span>
                    </button>
                    <button
                        onClick={() => switchTab('explanations')}
                        className={`flex-1 flex items-center justify-center gap-1 py-2 px-1.5 sm:px-3 rounded-2xl text-xs sm:text-sm font-semibold transition active:scale-[0.985] min-h-[48px] whitespace-nowrap ${activeTab === 'explanations' ? 'bg-orange-500 text-white shadow' : 'bg-white/5 text-orange-300 hover:bg-white/10'}`}
                    >
                        <BookOpen size={15} /> <span className="hidden sm:inline">Расшифровки</span><span className="sm:hidden">Смены</span>
                    </button>
                </div>
            </div>

            {/* ========== BLOCK 1: НАРЯДЫ (PDF viewer + list) ========== */}
            {activeTab === 'naryady' && (
                <div>
                    {/* Mobile friendly filters */}
                    <div className="mb-4 space-y-3">
                        <div className="relative">
                            <Search className="absolute left-4 top-4 h-5 w-5 text-orange-400" />
                            <input
                                type="text"
                                value={search}
                                onChange={e => setSearch(e.target.value)}
                                placeholder="Поиск по названию наряда или ФИО"
                                className="w-full rounded-3xl border border-white/10 bg-white/5 pl-12 py-3.5 text-[15px] text-orange-400 focus:outline-none focus:border-orange-400 placeholder:text-orange-300/50"
                            />
                        </div>
                        <div className="flex flex-col sm:flex-row gap-3">
                            <div className="flex-1">
                                <div className="text-[11px] uppercase tracking-widest text-orange-400 ml-1 mb-1">С даты</div>
                                <input type="date" value={dateFrom} onChange={e=>setDateFrom(e.target.value)} className="w-full rounded-3xl border border-white/10 bg-white/5 px-4 py-3 text-base text-orange-400" />
                            </div>
                            <div className="flex-1">
                                <div className="text-[11px] uppercase tracking-widest text-orange-400 ml-1 mb-1">По дату</div>
                                <input type="date" value={dateTo} onChange={e=>setDateTo(e.target.value)} className="w-full rounded-3xl border border-white/10 bg-white/5 px-4 py-3 text-base text-orange-400" />
                            </div>
                            <button
                                onClick={() => { setSearch(''); setDateFrom(''); setDateTo(''); }}
                                className="sm:self-end h-12 px-6 rounded-3xl border border-white/10 text-sm active:bg-white/5 text-orange-300"
                            >
                                Сбросить
                            </button>
                        </div>
                    </div>

                    {/* Cards grid — always comfortable on mobile */}
                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-6">
                        {filteredNaryads.length === 0 && (
                            <div className="col-span-full text-center py-10 text-orange-300 text-sm bg-white/5 rounded-3xl border border-white/10">Ничего не найдено по фильтрам</div>
                        )}
                        {filteredNaryads.map(n => (
                            <div
                                key={n.id}
                                onClick={() => openNaryad(n)}
                                className="cursor-pointer active:scale-[0.985] rounded-3xl border border-white/10 bg-white/5 p-4 hover:bg-white/10 transition flex flex-col"
                            >
                                <div className="font-semibold text-lg text-orange-300 leading-tight">{n.title}</div>
                                <div className="text-sm text-orange-400 mt-1">{n.naryad_date}</div>
                                <div className="mt-auto pt-3 text-[10px] text-right text-orange-500/70">Нажмите для просмотра →</div>
                            </div>
                        ))}
                    </div>

                    <div className="text-xs text-orange-400/60 px-1">Свайп в просмотрщике → листание страниц. Поиск внутри PDF работает.</div>

                    {/* ========== НОВЫЙ ПУНКТ: Пакетный поиск по именам (аналог C++ программы) ========== */}
                    <div className="mt-8 bg-[#0b1018] border border-white/10 rounded-3xl p-5">
                        <div className="flex items-center gap-2 text-orange-400 font-semibold mb-3">
                            <Search size={18} /> Поиск по именам в нарядах (как в старой программе на C++)
                        </div>
                        <div className="text-xs text-orange-300/70 mb-4">
                            Добавляйте "списки поиска" (фамилии/ФИО). Выбирайте наряды — получите текстовые результаты с совпадениями.
                        </div>

                        {/* Управление списками поиска */}
                        <div className="mb-4">
                            <div className="flex gap-2 mb-2">
                                <input
                                    value={newSearchListName}
                                    onChange={e => setNewSearchListName(e.target.value)}
                                    placeholder="Название списка (например Bobrov)"
                                    className="flex-1 rounded-2xl bg-white/5 border border-white/10 px-3 py-2 text-sm text-orange-400 placeholder:text-orange-300/50"
                                    onKeyDown={e => e.key === 'Enter' && addSearchList()}
                                />
                                <button onClick={addSearchList} className="px-4 py-2 rounded-2xl bg-orange-500 text-white text-sm active:bg-orange-600">+ Список</button>
                            </div>

                            {searchLists.length > 0 && (
                                <div className="space-y-2">
                                    {searchLists.map(list => (
                                        <div key={list.id} className="bg-white/5 rounded-2xl p-3 text-sm">
                                            <div className="flex justify-between items-center mb-1">
                                                <span className="font-medium text-orange-300">{list.name}</span>
                                                <div className="flex gap-2 text-xs">
                                                    <button onClick={() => setEditingListId(editingListId === list.id ? null : list.id)} className="text-orange-400 hover:underline">
                                                        {editingListId === list.id ? 'Закрыть' : 'Добавить'}
                                                    </button>
                                                    <button onClick={() => deleteSearchList(list.id)} className="text-red-400 hover:underline">Удалить</button>
                                                </div>
                                            </div>

                                            {/* Queries */}
                                            <div className="flex flex-wrap gap-1 mb-2">
                                                {list.queries.length === 0 && <span className="text-xs text-orange-300/60">Нет запросов</span>}
                                                {list.queries.map(q => (
                                                    <span key={q} className="bg-orange-500/20 text-orange-300 px-2 py-0.5 rounded text-xs flex items-center gap-1">
                                                        {q}
                                                        <button onClick={() => removeQueryFromList(list.id, q)} className="hover:text-red-400">×</button>
                                                    </span>
                                                ))}
                                            </div>

                                            {editingListId === list.id && (
                                                <div className="flex gap-2">
                                                    <input
                                                        value={newQueryForList}
                                                        onChange={e => setNewQueryForList(e.target.value)}
                                                        placeholder="ФИО или фамилия"
                                                        className="flex-1 text-xs rounded bg-black/40 border border-white/10 px-2 py-1"
                                                        onKeyDown={e => { if (e.key === 'Enter') { addQueryToList(list.id); } }}
                                                    />
                                                    <button onClick={() => addQueryToList(list.id)} className="text-xs px-3 py-1 bg-white/10 rounded">Добавить</button>
                                                </div>
                                            )}

                                            <label className="flex items-center gap-2 mt-2 text-xs cursor-pointer">
                                                <input type="checkbox" checked={selectedSearchListIds.includes(list.id)} onChange={() => toggleSearchList(list.id)} />
                                                Использовать в поиске
                                            </label>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </div>

                        {/* Выбор нарядов и запуск */}
                        <div>
                            <div className="text-xs font-semibold mb-1 text-orange-400">Выберите наряды для поиска:</div>
                            <div className="flex gap-2 mb-1 text-xs">
                                <button onClick={() => setSelectedNaryadIdsForSearch(filteredNaryads.map(n => n.id))} className="px-2 py-0.5 bg-white/10 rounded">Все видимые</button>
                                <button onClick={() => setSelectedNaryadIdsForSearch([])} className="px-2 py-0.5 bg-white/10 rounded">Сбросить</button>
                            </div>
                            <div className="grid grid-cols-2 sm:grid-cols-3 gap-1 max-h-32 overflow-auto mb-3 text-xs">
                                {filteredNaryads.map(n => (
                                    <label key={n.id} className="flex items-center gap-1.5 cursor-pointer bg-white/5 px-2 py-1 rounded">
                                        <input type="checkbox" checked={selectedNaryadIdsForSearch.includes(n.id)} onChange={() => toggleNaryadForSearch(n.id)} />
                                        <span className="truncate">{n.title} ({n.naryad_date})</span>
                                    </label>
                                ))}
                            </div>

                            <button
                                onClick={runBatchSearch}
                                disabled={isBatchSearching || selectedSearchListIds.length === 0 || selectedNaryadIdsForSearch.length === 0}
                                className="w-full h-11 rounded-2xl bg-orange-500 active:bg-orange-600 text-white font-bold text-sm disabled:opacity-50"
                            >
                                {isBatchSearching ? 'Идёт поиск...' : 'ЗАПУСТИТЬ ПОИСК ПО ВЫБРАННЫМ'}
                            </button>

                            {batchSearchResults.length > 0 && (
                                <div className="mt-4">
                                    <div className="flex justify-between items-center mb-2">
                                        <div className="font-semibold text-sm">Результаты ({batchSearchResults.length})</div>
                                        <button onClick={() => {
                                            const txt = batchSearchResults.map(r => {
                                                if (r.notFound) return `Не найдено: ${r.query} в ${r.naryadTitle}`;
                                                if (r.error) return `Ошибка: ${r.error}`;
                                                return `${r.naryadTitle} (стр.${r.page}) [${r.listName}] : ${r.query} → ${r.snippet}`;
                                            }).join('\n');
                                            const blob = new Blob([txt], {type: 'text/plain'});
                                            const url = URL.createObjectURL(blob);
                                            const a = document.createElement('a');
                                            a.href = url;
                                            a.download = 'naryad_search_results.txt';
                                            a.click();
                                            URL.revokeObjectURL(url);
                                        }} className="text-xs underline text-orange-400">Скачать .txt</button>
                                    </div>
                                    <div className="max-h-64 overflow-auto text-xs bg-black/40 p-3 rounded space-y-1 font-mono">
                                        {batchSearchResults.map((r, idx) => (
                                            <div key={idx} className={r.notFound ? 'text-red-400/70' : ''}>
                                                {r.error ? r.error :
                                                r.notFound ? `✗ ${r.query} не найдено в ${r.naryadTitle}` :
                                                `${r.naryadTitle} стр.${r.page} [${r.listName}]: ${r.snippet}`}
                                            </div>
                                        ))}
                                    </div>
                                    <button onClick={() => setBatchSearchResults([])} className="mt-2 text-xs text-orange-400/70 hover:text-orange-400">Очистить результаты</button>
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            )}

            {/* ========== BLOCK 2: СПРАВОЧНИК ТЕЛЕФОНОВ — полноценный поиск по ФИО ========== */}
            {activeTab === 'phones' && (
                <div className="space-y-4">
                    <div className="bg-white/5 border border-white/10 rounded-3xl p-4">
                        <div className="flex items-center gap-2 text-orange-400 text-sm font-semibold mb-1">
                            <Phone size={18} /> Справочник телефонов
                            {!isAdmin && <span className="text-[10px] text-orange-300/60">(только просмотр)</span>}
                        </div>

                        {/* Search by FIO — large */}
                        <div className="relative mb-4">
                            <Search className="absolute left-4 top-4 h-5 w-5 text-orange-400" />
                            <input
                                type="text"
                                value={phoneSearch}
                                onChange={e => setPhoneSearch(e.target.value)}
                                placeholder="Поиск по ФИО (начните вводить фамилию или имя)"
                                className="w-full rounded-3xl border border-white/10 bg-[#0b1018] pl-12 py-3.5 text-lg text-orange-400 focus:outline-none focus:border-orange-400 placeholder:text-orange-300/50"
                            />
                        </div>

                        {/* Add form — only for admins */}
                        {isAdmin && (
                            <>
                                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-3">
                                    <input
                                        type="text"
                                        value={newPhoneFio}
                                        onChange={e => setNewPhoneFio(e.target.value)}
                                        placeholder="ФИО полностью"
                                        className="rounded-3xl bg-[#0b1018] border border-white/10 px-4 py-3 text-base text-orange-400 placeholder:text-orange-300/60 focus:border-orange-400"
                                        onKeyDown={e => e.key === 'Enter' && addPhone()}
                                    />
                                    <input
                                        type="tel"
                                        value={newPhoneNum}
                                        onChange={e => setNewPhoneNum(e.target.value)}
                                        placeholder="+7 (___) ___-__-__"
                                        className="rounded-3xl bg-[#0b1018] border border-white/10 px-4 py-3 text-base text-orange-400 placeholder:text-orange-300/60 focus:border-orange-400"
                                        onKeyDown={e => e.key === 'Enter' && addPhone()}
                                    />
                                </div>
                                <button
                                    onClick={addPhone}
                                    className="w-full flex items-center justify-center gap-2 h-14 rounded-3xl bg-orange-500 active:bg-orange-600 text-white font-bold text-base shadow active:scale-[0.985]"
                                >
                                    <Plus size={20} /> ДОБАВИТЬ ТЕЛЕФОН
                                </button>
                                {phones.length > 0 && (
                                    <button onClick={() => { if (confirm('Очистить весь справочник телефонов?')) setPhones([]); }} className="mt-1 w-full text-xs text-red-400/70 underline py-1 active:text-red-400">Очистить справочник</button>
                                )}
                            </>
                        )}

                    </div>

                    {/* Phones list — big tappable rows */}
                    <div className="space-y-2">
                        {filteredPhones.length === 0 && <div className="text-orange-300 px-4 py-6 text-center bg-white/5 rounded-3xl">Ничего не найдено</div>}
                        {filteredPhones.map(p => (
                            <div key={p.id} className="flex items-center gap-3 bg-white/5 border border-white/10 rounded-3xl p-4 active:bg-white/10">
                                <div className="flex-1 min-w-0">
                                    <div className="font-semibold text-[17px] leading-tight text-orange-100">{p.fio}</div>
                                    <a href={`tel:${p.phone.replace(/[^0-9+]/g,'')}`} className="block mt-0.5 text-xl font-mono text-orange-400 active:text-orange-300 break-all">{p.phone}</a>
                                </div>
                                <button onClick={() => copyPhone(p.phone)} className="p-3 rounded-2xl bg-white/5 active:bg-white/10" title="Скопировать">
                                    <Copy size={19} />
                                </button>
                                {isAdmin && (
                                    <button onClick={() => deletePhone(p.id)} className="p-3 rounded-2xl bg-white/5 text-red-400 active:bg-white/10" title="Удалить">
                                        <Trash2 size={19} />
                                    </button>
                                )}
                            </div>
                        ))}
                    </div>

                    <p className="text-[11px] px-2 text-orange-400/60">Данные хранятся в вашем устройстве. Нажмите на номер — позвонить.</p>
                </div>
            )}

            {/* ========== BLOCK 3: РАСШИФРОВКИ СМЕН — добавление + чтение + поиск ========== */}
            {activeTab === 'explanations' && (
                <div className="space-y-4">
                    <div className="bg-white/5 border border-white/10 rounded-3xl p-4">
                        <div className="flex items-center gap-2 text-orange-400 text-sm font-semibold mb-1">
                            <BookOpen size={18} /> Расшифровка смен (справочник)
                            {!isAdmin && <span className="text-[10px] text-orange-300/60">(только просмотр)</span>}
                        </div>

                        {/* Search */}
                        <div className="relative mb-4">
                            <Search className="absolute left-4 top-4 h-5 w-5 text-orange-400" />
                            <input
                                type="text"
                                value={expSearch}
                                onChange={e => setExpSearch(e.target.value)}
                                placeholder="Поиск по коду смены или тексту расшифровки"
                                className="w-full rounded-3xl border border-white/10 bg-[#0b1018] pl-12 py-3.5 text-lg text-orange-400 focus:outline-none focus:border-orange-400 placeholder:text-orange-300/50"
                            />
                        </div>

                        {/* Add form — only for admins */}
                        {isAdmin && (
                            <div className="space-y-3 mb-2">
                                <input
                                    type="text"
                                    value={newExpShift}
                                    onChange={e => setNewExpShift(e.target.value)}
                                    placeholder="Код смены, напр: 12 (любой) или 5 (чётная) [ночь]"
                                    className="w-full rounded-3xl bg-[#0b1018] border border-white/10 px-4 py-3 text-base text-orange-400"
                                    onKeyDown={e => e.key === 'Enter' && newExpText.trim() && addExplanation()}
                                />
                                <textarea
                                    value={newExpText}
                                    onChange={e => setNewExpText(e.target.value)}
                                    placeholder="Полная расшифровка: время выхода, особенности, перерывы, подмены, «с ночи» и т.д."
                                    rows={3}
                                    className="w-full rounded-3xl bg-[#0b1018] border border-white/10 px-4 py-3 text-[15px] text-orange-400 resize-y"
                                />
                                <button
                                    onClick={addExplanation}
                                    className="w-full flex items-center justify-center gap-2 h-14 rounded-3xl bg-orange-500 active:bg-orange-600 text-white font-bold text-base shadow active:scale-[0.985]"
                                >
                                    <Plus size={20} /> ДОБАВИТЬ РАСШИФРОВКУ
                                </button>
                                {explanations.length > 0 && (
                                    <button onClick={() => { if (confirm('Очистить все расшифровки?')) setExplanations([]); }} className="mt-1 w-full text-xs text-red-400/70 underline py-1 active:text-red-400">Очистить все расшифровки</button>
                                )}
                            </div>
                        )}

                    </div>

                    {/* List of explanations — readable cards */}
                    <div className="space-y-3">
                        {filteredExps.length === 0 && (
                            <div className="text-orange-300 px-4 py-8 text-center bg-white/5 rounded-3xl">Ничего не найдено. Добавьте первую расшифровку.</div>
                        )}
                        {filteredExps.map(e => (
                            <div key={e.id} className="bg-white/5 border border-white/10 rounded-3xl p-4">
                                <div className="flex items-start justify-between gap-3">
                                    <div className="font-mono text-orange-300 text-sm tracking-wider">{e.shift}</div>
                                    {isAdmin && (
                                        <button onClick={() => deleteExplanation(e.id)} className="text-red-400/80 p-1 active:scale-95">
                                            <Trash2 size={18} />
                                        </button>
                                    )}
                                </div>
                                <div className="mt-1.5 text-[15px] leading-snug text-orange-100 whitespace-pre-line">{e.text}</div>
                                {e.date && <div className="mt-2 text-[10px] text-orange-400/50">Добавлено: {e.date}</div>}
                            </div>
                        ))}
                    </div>

                    <p className="text-[11px] px-2 text-orange-400/60">Хранится локально. Используйте для быстрого доступа на смене.</p>
                </div>
            )}

            {/* PDF VIEWER MODAL — enhanced for mobile */}
            {selected && pdfUrl && (
                <div className="fixed inset-0 z-[90] bg-black/90 flex items-start sm:items-center justify-center p-0 sm:p-4" onClick={close}>
                    <div
                        className="bg-[#0b1018] w-full sm:rounded-3xl sm:max-w-5xl sm:h-[94vh] h-screen flex flex-col border-0 sm:border border-white/10 overflow-hidden"
                        onClick={e => e.stopPropagation()}
                    >
                        {/* Header */}
                        <div className="p-3 border-b border-white/10 flex items-center justify-between text-sm shrink-0">
                            <div className="font-bold pr-2 truncate">{selected.title} • {selected.naryad_date}</div>
                            <button onClick={close} className="p-2 text-3xl leading-none text-orange-300 active:text-white">×</button>
                        </div>

                        {/* Toolbar — big tap targets */}
                        <div className="bg-[#0f141d] border-b border-white/10 px-2 py-2 flex flex-wrap gap-x-2 gap-y-1 items-center text-sm">
                            <div className="flex items-center gap-1">
                                <button onClick={() => changePage(-1)} disabled={!pdfDoc} className="h-11 w-11 flex items-center justify-center rounded-2xl bg-white/5 active:bg-white/10 disabled:opacity-40"><ChevronLeft size={20} /></button>
                                <div className="px-3 tabular-nums">{currentPage} / {numPages || '?'}</div>
                                <button onClick={() => changePage(1)} disabled={!pdfDoc} className="h-11 w-11 flex items-center justify-center rounded-2xl bg-white/5 active:bg-white/10 disabled:opacity-40"><ChevronRight size={20} /></button>
                            </div>

                            <div className="w-px h-6 bg-white/10 mx-0.5 hidden sm:block" />

                            <div className="flex items-center gap-1">
                                <button onClick={() => changeScale(-0.25)} disabled={!pdfDoc} className="h-11 px-3 rounded-2xl bg-white/5 active:bg-white/10 disabled:opacity-40 flex items-center gap-1"><ZoomOut size={17} /> <span className="text-xs">–</span></button>
                                <div className="px-2 tabular-nums w-[54px] text-center">{Math.round(scale * 100)}%</div>
                                <button onClick={() => changeScale(0.25)} disabled={!pdfDoc} className="h-11 px-3 rounded-2xl bg-white/5 active:bg-white/10 disabled:opacity-40 flex items-center gap-1"><ZoomIn size={17} /> <span className="text-xs">+</span></button>
                            </div>

                            <div className="flex-1" />

                            {/* PDF internal search */}
                            <div className="flex w-full sm:w-auto items-center gap-1.5 mt-1 sm:mt-0">
                                <input
                                    value={pdfSearchTerm}
                                    onChange={e => setPdfSearchTerm(e.target.value)}
                                    onKeyDown={e => e.key === 'Enter' && performAdvancedSearch(pdfSearchTerm)}
                                    placeholder="Фамилия в PDF..."
                                    className="flex-1 sm:w-48 bg-white/10 border border-white/20 rounded-2xl px-4 py-2 text-sm text-orange-400 placeholder:text-orange-300/50"
                                />
                                <button
                                    onClick={() => performAdvancedSearch(pdfSearchTerm)}
                                    disabled={!pdfDoc}
                                    className="px-4 h-11 rounded-2xl bg-orange-600 active:bg-orange-700 text-sm font-semibold shrink-0"
                                >
                                    Найти
                                </button>
                            </div>

                            {searchMatches.length > 0 && (
                                <div className="text-xs px-2 py-1 rounded bg-black/60 border border-orange-400/40 flex items-center gap-1">
                                    {currentMatchIndex + 1}/{searchMatches.length}
                                    <button onClick={() => goToMatch((currentMatchIndex + 1) % searchMatches.length)} className="underline">след.</button>
                                </div>
                            )}
                        </div>

                        {/* Canvas area with swipe support */}
                        <div ref={pdfContainerRef} className="flex-1 overflow-auto bg-[#0a0f17] p-2 sm:p-4 flex justify-center items-start touch-none select-none" style={{ minHeight: '300px', touchAction: 'none' }}>
                            {pdfDoc ? (
                                <canvas ref={canvasRef} className="shadow-xl border border-white/10 max-w-full" style={{ touchAction: 'pan-x pan-y' }} />
                            ) : (
                                <iframe src={`${pdfUrl}#toolbar=0&navpanes=0`} className="w-full h-full border-none bg-white" />
                            )}
                        </div>

                        <div className="p-2 text-[11px] text-orange-400/70 border-t border-white/10 text-center shrink-0">
                            Свайп влево/вправо — страницы • Поиск подсвечивает жёлтым • Масштаб и зум доступны
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}