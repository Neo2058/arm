// import React, { useEffect, useMemo, useState } from 'react';
// import axios from 'axios';
// import { motion, AnimatePresence } from 'framer-motion';
//
// import {
//     FileText,
//     Search,
//     Shield,
//     ArrowLeft,
//     Eye,
//     Lock,
//     X,
//     PlayCircle,
//     ChevronDown,
//     Folder,
// } from 'lucide-react';
//
// export default function SecureDocumentViewer({
//                                                  categories = [],
//                                                  onBack,
//                                              }) {
//
//     const [selectedDocument, setSelectedDocument] = useState(null);
//     const [search, setSearch] = useState('');
//     const [openCategory, setOpenCategory] = useState(null);
//
//     const filteredCategories = useMemo(() => {
//
//         if (!search.trim()) {
//             return categories;
//         }
//
//         return categories
//             .map(category => ({
//                 ...category,
//                 items: category.items.filter(doc =>
//                     doc.title
//                         .toLowerCase()
//                         .includes(search.toLowerCase())
//                 ),
//             }))
//             .filter(category => category.items.length > 0);
//
//     }, [categories, search]);
//
//     useEffect(() => {
//
//         if (search && filteredCategories.length > 0) {
//             setOpenCategory(filteredCategories[0].name);
//         }
//
//     }, [search, filteredCategories]);
//
//     useEffect(() => {
//
//         const disableActions = (e) => {
//
//             if (
//                 (e.ctrlKey || e.metaKey)
//                 && ['s','p','u'].includes(e.key.toLowerCase())
//             ) {
//                 e.preventDefault();
//             }
//         };
//
//         const disableContext = e => e.preventDefault();
//
//         document.addEventListener('keydown', disableActions);
//         document.addEventListener('contextmenu', disableContext);
//
//         return () => {
//             document.removeEventListener('keydown', disableActions);
//             document.removeEventListener('contextmenu', disableContext);
//         };
//
//     }, []);
//
//     const toggleCategory = (name) => {
//
//         setOpenCategory(
//             openCategory === name
//                 ? null
//                 : name
//         );
//     };
//
//     const handleDocumentClick = async (doc) => {
//
//         setSelectedDocument(doc);
//
//         if (!doc) {
//             return;
//         }
//
//         try {
//
//             await axios.get(
//                 `/api/documents/${doc.id}/click`
//             );
//
//         } catch (error) {
//
//             console.error(
//                 'Ошибка логирования активности',
//                 error
//             );
//         }
//     };
//
//     return (
//
//         <section className="relative min-h-screen overflow-hidden bg-[#0b1018] text-white">
//
//             <div className="absolute inset-0 bg-[radial-gradient(circle_at_top,rgba(255,140,0,0.15),transparent_40%)]"/>
//
//             <div className="relative z-10 mx-auto flex max-w-7xl flex-col gap-6 px-4 py-6 lg:flex-row lg:px-8">
//
//                 {/* SIDEBAR */}
//
//                 <motion.aside
//                     initial={{opacity:0,x:-30}}
//                     animate={{opacity:1,x:0}}
//                     className="w-full lg:w-[420px] rounded-3xl border border-white/10 bg-white/5 backdrop-blur-xl flex flex-col h-[85vh]"
//                 >
//
//                     <div className="border-b border-white/10 p-6">
//
//                         <div className="mb-5 flex items-center justify-between">
//
//                             <div>
//
//                                 <div className="mb-2 flex items-center gap-2 text-orange-300">
//
//                                     <Shield className="h-5 w-5"/>
//
//                                     <span className="text-xs uppercase tracking-[0.25em]">
//                                         Защищённый доступ
//                                     </span>
//
//                                 </div>
//
//                                 <h1 className="text-3xl font-black">
//                                     Документы
//                                 </h1>
//
//                             </div>
//
//                             {onBack && (
//
//                                 <button
//                                     onClick={onBack}
//                                     className="rounded-xl border border-white/10 bg-white/5 p-3 hover:bg-white/10"
//                                 >
//                                     <ArrowLeft className="h-5 w-5"/>
//                                 </button>
//
//                             )}
//
//                         </div>
//
//                         <div className="relative">
//
//                             <Search className="absolute left-4 top-1/2 -translate-y-1/2 h-4 w-4 text-zinc-400"/>
//
//                             <input
//                                 value={search}
//                                 onChange={(e)=>setSearch(e.target.value)}
//                                 placeholder="Поиск документа..."
//                                 className="h-12 w-full rounded-2xl border border-white/10 bg-black/20 pl-11 pr-4 text-sm outline-none focus:border-orange-400/40"
//                             />
//
//                         </div>
//
//                     </div>
//
//                     <div className="flex-1 overflow-y-auto p-4 space-y-3">
//
//                         {filteredCategories.map(category => {
//
//                             const opened =
//                                 openCategory === category.name;
//
//                             return (
//
//                                 <div
//                                     key={category.name}
//                                     className="rounded-2xl border border-white/5 overflow-hidden"
//                                 >
//
//                                     <button
//                                         onClick={() =>
//                                             toggleCategory(category.name)
//                                         }
//                                         className={`flex w-full justify-between p-4 transition ${
//                                             opened
//                                                 ? 'bg-orange-500/10 text-orange-400'
//                                                 : 'hover:bg-white/5'
//                                         }`}
//                                     >
//
//                                         <div className="flex items-center gap-3">
//
//                                             <Folder className="h-5 w-5"/>
//
//                                             <span>
//                                                 {category.name}
//                                             </span>
//
//                                         </div>
//
//                                         <ChevronDown
//                                             className={`h-4 w-4 transition ${
//                                                 opened
//                                                     ? 'rotate-180'
//                                                     : ''
//                                             }`}
//                                         />
//
//                                     </button>
//
//                                     <AnimatePresence>
//
//                                         {opened && (
//
//                                             <motion.div
//                                                 initial={{height:0,opacity:0}}
//                                                 animate={{height:'auto',opacity:1}}
//                                                 exit={{height:0,opacity:0}}
//                                                 className="bg-black/10 p-2 space-y-2"
//                                             >
//
//                                                 {category.items.map(doc => {
//
//                                                     const active =
//                                                         selectedDocument?.id === doc.id;
//
//                                                     return (
//
//                                                         <button
//                                                             key={doc.id}
//                                                             onClick={() => handleDocumentClick(doc)}
//                                                             className={`w-full rounded-xl p-3 text-left transition ${
//                                                                 active
//                                                                     ? 'bg-orange-400/10 border border-orange-400/30'
//                                                                     : 'hover:bg-white/5'
//                                                             }`}
//                                                         >
//
//                                                             <div className="flex items-start gap-3">
//
//                                                                 <FileText className="h-4 w-4 flex-shrink-0 mt-0.5" />
//
//                                                                 <div className="flex-1 min-w-0">
//
//                                                                     <span className="block truncate text-sm">
//                                                                         {doc.title}
//                                                                     </span>
//
//                                                                     {doc.quiz && (
//
//                                                                         <div className="mt-2 flex items-center gap-1 text-[10px] font-bold uppercase tracking-wider text-cyan-400">
//
//                                                                             <div className="h-1.5 w-1.5 rounded-full bg-cyan-400 animate-pulse" />
//
//                                                                             Доступен тест
//
//                                                                         </div>
//
//                                                                     )}
//
//                                                                 </div>
//
//                                                                 <Eye className="h-4 w-4 flex-shrink-0" />
//
//                                                             </div>
//
//                                                         </button>
//
//                                                     );
//                                                 })}
//
//                                             </motion.div>
//
//                                         )}
//
//                                     </AnimatePresence>
//
//                                 </div>
//
//                             );
//                         })}
//
//                     </div>
//
//                 </motion.aside>
//
//                 {/* VIEWER */}
//
//                 <motion.main
//                     initial={{opacity:0,y:20}}
//                     animate={{opacity:1,y:0}}
//                     className="flex-1 rounded-3xl border border-white/10 bg-white/5 backdrop-blur-xl overflow-hidden"
//                 >
//
//                     <AnimatePresence mode="wait">
//
//                         {selectedDocument ? (
//
//                             <motion.div
//                                 key={selectedDocument.id}
//                                 initial={{opacity:0}}
//                                 animate={{opacity:1}}
//                                 exit={{opacity:0}}
//                                 className="h-full flex flex-col"
//                             >
//
//                                 <div className="flex justify-between border-b border-white/10 px-6 py-4">
//
//                                     <div>
//
//                                         <h2 className="text-2xl font-bold">
//                                             {selectedDocument.title}
//                                         </h2>
//
//                                         <div className="mt-1 flex items-center gap-2 text-xs text-zinc-400 uppercase">
//
//                                             <Shield className="h-3 w-3"/>
//
//                                             Protected document mode
//
//                                         </div>
//
//                                     </div>
//
//                                     <button
//                                         onClick={() =>
//                                             handleDocumentClick(null)
//                                         }
//                                         className="rounded-xl border border-white/10 p-3"
//                                     >
//                                         <X className="h-5 w-5"/>
//                                     </button>
//
//                                 </div>
//
//                                 <div className="relative flex-1 bg-black">
//
//                                     <iframe
//                                         src={`${selectedDocument.url}#toolbar=0`}
//                                         className="w-full h-full border-none"
//                                         title={selectedDocument.title}
//                                     />
//
//                                 </div>
//
//                                 {selectedDocument.quiz && (
//
//                                     <div className="absolute bottom-8 right-8">
//
//                                         <button
//                                             onClick={() =>
//                                                 window.location.href =
//                                                     `/quiz/${selectedDocument.quiz.id}`
//                                             }
//                                             className="flex items-center gap-3 rounded-2xl bg-orange-500 px-6 py-3 font-bold hover:bg-orange-600"
//                                         >
//
//                                             <PlayCircle className="h-5 w-5"/>
//
//                                             Пройти тест
//
//                                         </button>
//
//                                     </div>
//
//                                 )}
//
//                             </motion.div>
//
//                         ) : (
//
//                             <div className="flex h-full items-center justify-center p-8 text-center">
//
//                                 <div>
//
//                                     <FileText className="mx-auto h-16 w-16 text-orange-400"/>
//
//                                     <h2 className="mt-6 text-4xl font-black">
//                                         Выберите документ
//                                     </h2>
//
//                                     <p className="mt-4 text-zinc-400">
//
//                                         Доступ к документации осуществляется
//                                         в защищённом режиме.
//
//                                     </p>
//
//                                 </div>
//
//                             </div>
//
//                         )}
//
//                     </AnimatePresence>
//
//                 </motion.main>
//
//             </div>
//
//         </section>
//     );
// }
import React, { useEffect, useMemo, useState, useRef } from 'react';
import axios from 'axios';
import { motion, AnimatePresence } from 'framer-motion';

import {
    FileText,
    Search,
    Shield,
    ArrowLeft,
    Eye,
    X,
    PlayCircle,
    ChevronDown,
    Folder,
} from 'lucide-react';

export default function SecureDocumentViewer({
                                                 categories = [],
                                                 onBack,
                                             }) {

    const [selectedDocument, setSelectedDocument] = useState(null);
    const [search, setSearch] = useState('');
    const [openCategory, setOpenCategory] = useState(null);

    // PDF.js state for protected rendering (no direct download possible)
    const [pdfDoc, setPdfDoc] = useState(null);
    const [currentPage, setCurrentPage] = useState(1);
    const [numPages, setNumPages] = useState(0);
    const [scale, setScale] = useState(1.5);
    const [isLoadingPdf, setIsLoadingPdf] = useState(false);
    const [loadError, setLoadError] = useState(null);
    const [displayWidth, setDisplayWidth] = useState(0);
    const [displayHeight, setDisplayHeight] = useState(0);
    const canvasRef = useRef(null);
    const pdfContainerRef = useRef(null);
    const touchStartX = useRef(0);

    // For document text search
    const [docSearchTerm, setDocSearchTerm] = useState('');
    const [searchMatches, setSearchMatches] = useState([]); // [{page, snippet}]

    const filteredCategories = useMemo(() => {

        if (!search.trim()) return categories;

        return categories
            .map(category => ({
                ...category,
                items: category.items.filter(doc =>
                    doc.title.toLowerCase().includes(search.toLowerCase())
                ),
            }))
            .filter(category => category.items.length > 0);

    }, [categories, search]);

    useEffect(() => {

        if (search && filteredCategories.length > 0) {
            setOpenCategory(filteredCategories[0].name);
        }

    }, [search, filteredCategories]);

    // Block shortcuts + right click (secure mode)
    useEffect(() => {

        const disableActions = (e) => {
            if ((e.ctrlKey || e.metaKey) && ['s', 'p', 'u'].includes(e.key.toLowerCase())) {
                e.preventDefault();
            }
        };

        const disableContext = e => e.preventDefault();

        document.addEventListener('keydown', disableActions);
        document.addEventListener('contextmenu', disableContext);

        return () => {
            document.removeEventListener('keydown', disableActions);
            document.removeEventListener('contextmenu', disableContext);
        };

    }, []);

    // ESC close modal + scroll lock
    useEffect(() => {

        const handleEsc = (e) => {
            if (e.key === 'Escape') {
                setSelectedDocument(null);
            }
        };

        if (selectedDocument) {
            // Avoid overflow: hidden — it kills native PDF gestures on iOS.
            // Use overscroll-behavior instead (less invasive).
            document.documentElement.style.overscrollBehavior = 'none';
            document.body.style.overscrollBehavior = 'none';
            // Some iOS WebKit issues are helped by this too
            document.body.style.position = 'relative';
        } else {
            document.documentElement.style.overscrollBehavior = '';
            document.body.style.overscrollBehavior = '';
            document.body.style.position = '';
        }

        window.addEventListener('keydown', handleEsc);

        return () => {
            window.removeEventListener('keydown', handleEsc);
            document.documentElement.style.overscrollBehavior = '';
            document.body.style.overscrollBehavior = '';
            document.body.style.position = '';
        };

    }, [selectedDocument]);

    // Load PDF.js and the document when selected (protected: we fetch bytes ourselves)
    useEffect(() => {
        if (!selectedDocument?.url) {
            setPdfDoc(null);
            setNumPages(0);
            setCurrentPage(1);
            setIsLoadingPdf(false);
            setLoadError(null);
            setDocSearchTerm('');
            setSearchMatches([]);
            return;
        }

        let cancelled = false;
        setIsLoadingPdf(true);
        setLoadError(null);

        (async () => {
            try {
                // Ensure PDF.js is loaded
                if (!window.pdfjsLib) {
                    await new Promise((resolve, reject) => {
                        const script = document.createElement('script');
                        script.src = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js';
                        script.onload = () => {
                            window.pdfjsLib.GlobalWorkerOptions.workerSrc =
                                'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
                            resolve();
                        };
                        script.onerror = reject;
                        document.head.appendChild(script);
                    });
                }

                const pdfjs = window.pdfjsLib;

                // Fetch bytes ourselves — never give browser a direct file link
                const response = await fetch(selectedDocument.url);
                if (!response.ok) throw new Error('Failed to fetch document');
                const arrayBuffer = await response.arrayBuffer();

                const pdf = await pdfjs.getDocument({ data: arrayBuffer }).promise;

                if (cancelled) return;

                setPdfDoc(pdf);
                setNumPages(pdf.numPages);
                setCurrentPage(1);
                setScale(1.5);
                setIsLoadingPdf(false);

                // Extract text for in-document search
                const extracted = [];
                for (let p = 1; p <= pdf.numPages; p++) {
                    const pg = await pdf.getPage(p);
                    const content = await pg.getTextContent();
                    const text = content.items.map(item => item.str).join(' ');
                    extracted.push({ page: p, text });
                }
                window.__docPageTexts = extracted;

            } catch (err) {
                console.error('Failed to load PDF:', err);
                if (!cancelled) {
                    setLoadError('Не удалось загрузить документ. Попробуйте обновить страницу.');
                    setIsLoadingPdf(false);
                }
            }
        })();

        return () => { cancelled = true; };
    }, [selectedDocument]);

    // Render current page to canvas with proper visual zoom (fixes mobile zoom not enlarging the document)
    useEffect(() => {
        if (!pdfDoc || !canvasRef.current) return;

        (async () => {
            try {
                const page = await pdfDoc.getPage(currentPage);

                const baseViewport = page.getViewport({ scale: 1 });
                const qualityMultiplier = 1.5;
                const renderScale = scale * qualityMultiplier;
                const viewport = page.getViewport({ scale: renderScale });

                const canvas = canvasRef.current;
                const ctx = canvas.getContext('2d', { alpha: true });

                canvas.width = viewport.width;
                canvas.height = viewport.height;

                // Explicit style size for visual zoom of the document content
                const dispW = baseViewport.width * scale;
                const dispH = baseViewport.height * scale;
                canvas.style.width = `${dispW}px`;
                canvas.style.height = `${dispH}px`;

                setDisplayWidth(dispW);
                setDisplayHeight(dispH);

                await page.render({ canvasContext: ctx, viewport }).promise;
            } catch (err) {
                console.error('PDF render error:', err);
            }
        })();
    }, [pdfDoc, currentPage, scale]);

    // Touch swipe + pinch-to-zoom + support for panning the zoomed document
    useEffect(() => {
        const el = pdfContainerRef.current;
        if (!el || !pdfDoc) return;

        let initialDistance = 0;
        let initialScaleOnPinch = 1;
        let isPinching = false;

        const getDistance = (t1, t2) => {
            const dx = t1.clientX - t2.clientX;
            const dy = t1.clientY - t2.clientY;
            return Math.sqrt(dx * dx + dy * dy);
        };

        const onTouchStart = (e) => {
            if (e.touches.length === 1) {
                touchStartX.current = e.touches[0].clientX;
                isPinching = false;
            } else if (e.touches.length === 2) {
                isPinching = true;
                initialDistance = getDistance(e.touches[0], e.touches[1]);
                initialScaleOnPinch = scale;
            }
        };

        const onTouchMove = (e) => {
            if (e.touches.length === 2) {
                e.preventDefault();
                const distance = getDistance(e.touches[0], e.touches[1]);
                if (initialDistance > 0) {
                    const ratio = distance / initialDistance;
                    const newScale = Math.max(0.5, Math.min(4, initialScaleOnPinch * ratio));

                    // Adjust scroll so the pinch center stays under the fingers (fixes "zooms to one point" on iOS)
                    const rect = el.getBoundingClientRect();
                    const centerClientX = (e.touches[0].clientX + e.touches[1].clientX) / 2 - rect.left;
                    const centerClientY = (e.touches[0].clientY + e.touches[1].clientY) / 2 - rect.top;

                    const oldScrollLeft = el.scrollLeft;
                    const oldScrollTop = el.scrollTop;

                    const contentCenterX = oldScrollLeft + centerClientX;
                    const contentCenterY = oldScrollTop + centerClientY;

                    const scrollRatio = newScale / scale;
                    const newScrollLeft = contentCenterX * scrollRatio - centerClientX;
                    const newScrollTop = contentCenterY * scrollRatio - centerClientY;

                    el.scrollLeft = newScrollLeft;
                    el.scrollTop = newScrollTop;

                    setScale(newScale);
                }
            }
            // When zoomed, overflow-auto on parent handles panning
        };

        const onTouchEnd = (e) => {
            if (isPinching) {
                isPinching = false;
                return;
            }
            if (!pdfDoc || e.touches.length > 0) return;

            const endX = e.changedTouches[0].clientX;
            const delta = endX - touchStartX.current;

            if (Math.abs(delta) > 70 && scale <= 1.4) {
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

    const changePage = (delta) => {
        setCurrentPage(p => Math.max(1, Math.min(numPages, p + delta)));
    };

    const changeScale = (delta) => {
        setScale(s => Math.max(0.6, Math.min(3.5, s + delta)));
    };

    // Fullscreen support - keeps all zoom, nav, search features
    const toggleFullscreen = () => {
        const container = pdfContainerRef.current;
        if (!container) return;

        if (document.fullscreenElement) {
            document.exitFullscreen().catch(() => {});
        } else {
            container.requestFullscreen().catch((err) => {
                console.warn('Fullscreen failed:', err);
                // Fallback for older browsers or iOS (limited support)
                if (container.webkitRequestFullscreen) {
                    container.webkitRequestFullscreen();
                }
            });
        }
    };

    const toggleCategory = (name) => {
        setOpenCategory(prev => prev === name ? null : name);
    };

    const handleDocumentClick = async (doc) => {

        setSelectedDocument(doc);

        if (!doc) return;

        try {
            await axios.get(`/api/documents/${doc.id}/click`);
        } catch (error) {
            console.error('Ошибка логирования активности', error);
        }
    };

    return (
        <section className={`relative min-h-screen ${selectedDocument ? 'overflow-visible' : 'overflow-hidden'} bg-[#0b1018] text-white`}>

            <div className="absolute inset-0 bg-[radial-gradient(circle_at_top,rgba(255,140,0,0.15),transparent_40%)]" />

            <div className="relative z-10 mx-auto flex max-w-7xl flex-col gap-6 px-4 py-6 lg:flex-row lg:px-8">

                {/* SIDEBAR */}
                <motion.aside
                    initial={{ opacity: 0, x: -30 }}
                    animate={{ opacity: 1, x: 0 }}
                    className="w-full lg:w-[420px] rounded-3xl border border-white/10 bg-white/5 backdrop-blur-xl flex flex-col h-[85vh]"
                >

                    <div className="border-b border-white/10 p-6">

                        <div className="mb-5 flex items-center justify-between">

                            <div>
                                <div className="mb-2 flex items-center gap-2 text-orange-300">
                                    <Shield className="h-5 w-5" />
                                    <span className="text-xs uppercase tracking-[0.25em]">
                                        Защищённый доступ
                                    </span>
                                </div>

                                <h1 className="text-3xl font-black">
                                    Документы
                                </h1>
                            </div>

                            {onBack && (
                                <button
                                    onClick={onBack}
                                    className="rounded-xl border border-white/10 bg-white/5 p-3 hover:bg-white/10"
                                >
                                    <ArrowLeft className="h-5 w-5" />
                                </button>
                            )}

                        </div>

                        <div className="relative">

                            <Search className="absolute left-4 top-1/2 -translate-y-1/2 h-4 w-4 text-zinc-400" />

                            <input
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="Поиск документа..."
                                className="h-12 w-full rounded-2xl border border-white/10 bg-black/20 pl-11 pr-4 text-sm outline-none focus:border-orange-400/40"
                            />

                        </div>

                    </div>

                    <div className="flex-1 overflow-y-auto p-4 space-y-3">

                        {filteredCategories.map(category => {

                            const opened = openCategory === category.name;

                            return (
                                <div key={category.name} className="rounded-2xl border border-white/5 overflow-hidden">

                                    <button
                                        onClick={() => toggleCategory(category.name)}
                                        className={`flex w-full justify-between p-4 transition ${
                                            opened ? 'bg-orange-500/10 text-orange-400' : 'hover:bg-white/5'
                                        }`}
                                    >

                                        <div className="flex items-center gap-3">
                                            <Folder className="h-5 w-5" />
                                            <span>{category.name}</span>
                                        </div>

                                        <ChevronDown className={`h-4 w-4 transition ${opened ? 'rotate-180' : ''}`} />

                                    </button>

                                    <AnimatePresence>

                                        {opened && (
                                            <motion.div
                                                initial={{ height: 0, opacity: 0 }}
                                                animate={{ height: 'auto', opacity: 1 }}
                                                exit={{ height: 0, opacity: 0 }}
                                                className="bg-black/10 p-2 space-y-2"
                                            >

                                                {category.items.map(doc => {

                                                    const active = selectedDocument?.id === doc.id;

                                                    return (
                                                        <button
                                                            key={doc.id}
                                                            onClick={() => handleDocumentClick(doc)}
                                                            className={`w-full rounded-xl p-3 text-left transition ${
                                                                active
                                                                    ? 'bg-orange-400/10 border border-orange-400/30'
                                                                    : 'hover:bg-white/5'
                                                            }`}
                                                        >

                                                            <div className="flex items-start gap-3">

                                                                <FileText className="h-4 w-4 mt-0.5" />

                                                                <div className="flex-1 min-w-0">
                                                                    <span className="block truncate text-sm">
                                                                        {doc.title}
                                                                    </span>
                                                                </div>

                                                                <Eye className="h-4 w-4" />

                                                            </div>

                                                        </button>
                                                    );

                                                })}

                                            </motion.div>
                                        )}

                                    </AnimatePresence>

                                </div>
                            );

                        })}

                    </div>

                </motion.aside>

            </div>

            {/* FULLSCREEN MODAL VIEWER */}
            <AnimatePresence>

                {selectedDocument && (

                    <motion.div
                        className="fixed inset-0 z-[9999] flex items-center justify-center bg-black/90 backdrop-blur-md p-4"
                        initial={{ opacity: 0 }}
                        animate={{ opacity: 1 }}
                        exit={{ opacity: 0 }}
                        onClick={() => setSelectedDocument(null)}
                    >

                        <motion.div
                            initial={{ opacity: 0, scale: 0.95, y: 30 }}
                            animate={{ opacity: 1, scale: 1, y: 0 }}
                            exit={{ opacity: 0, scale: 0.95, y: 20 }}
                            transition={{ type: 'spring', stiffness: 200, damping: 25 }}
                            onClick={(e) => e.stopPropagation()}
                            className="relative flex h-[95vh] w-full max-w-7xl flex-col overflow-hidden rounded-3xl border border-white/10 bg-[#0d131d]"
                        >

                            {/* HEADER */}
                            <div className="flex items-center justify-between border-b border-white/10 px-6 py-5">

                                <div>
                                    <h2 className="text-2xl font-black">
                                        {selectedDocument.title}
                                    </h2>

                                    <div className="mt-1 flex items-center gap-2 text-xs uppercase text-zinc-400">
                                        <Shield className="h-3 w-3" />
                                        Secure viewer (PDF.js — только просмотр)
                                    </div>
                                </div>

                                <button
                                    onClick={() => setSelectedDocument(null)}
                                    className="rounded-xl border border-white/10 bg-white/5 p-3 hover:bg-white/10"
                                >
                                    <X className="h-5 w-5" />
                                </button>

                            </div>

                            {/* PDF.js canvas viewer — мы полностью контролируем рендер.
                                PDF никогда не отдаётся браузеру как файл. Только пиксели на canvas. */}
                            <div className="flex flex-col flex-1 min-h-0">
                                {/* Top bar: text search + nice page nav + zoom */}
                                <div className="flex items-center gap-2 p-2 bg-black/70 border-b border-white/10 text-sm text-white flex-wrap sticky top-0 z-10">
                                    {/* Text search */}
                                    <div className="flex-1 min-w-[160px]">
                                        <input
                                            type="text"
                                            value={docSearchTerm}
                                            onChange={(e) => {
                                                const val = e.target.value;
                                                setDocSearchTerm(val);
                                                if (!val || !window.__docPageTexts) {
                                                    setSearchMatches([]);
                                                    return;
                                                }
                                                const q = val.toLowerCase();
                                                const matches = [];
                                                window.__docPageTexts.forEach(pt => {
                                                    if (pt.text.toLowerCase().includes(q)) {
                                                        const idx = pt.text.toLowerCase().indexOf(q);
                                                        const snip = pt.text.substring(Math.max(0, idx - 40), idx + val.length + 40);
                                                        matches.push({ page: pt.page, snippet: snip });
                                                    }
                                                });
                                                setSearchMatches(matches);
                                                if (matches.length > 0) setCurrentPage(matches[0].page);
                                            }}
                                            placeholder="Поиск по тексту в документе..."
                                            className="w-full bg-white/10 border border-white/20 rounded px-3 py-1 text-sm placeholder:text-zinc-400"
                                        />
                                    </div>

                                    {/* Nice page navigation */}
                                    <div className="flex items-center gap-1 bg-white/10 rounded-full px-1 py-0.5 text-xs">
                                        <button onClick={() => changePage(-1)} disabled={currentPage <= 1} className="px-2.5 py-1 disabled:opacity-40 active:bg-white/20 rounded-full">← Пред.</button>
                                        <span className="px-2 tabular-nums select-none">{currentPage} / {numPages || '?'}</span>
                                        <button onClick={() => changePage(1)} disabled={currentPage >= numPages} className="px-2.5 py-1 disabled:opacity-40 active:bg-white/20 rounded-full">След. →</button>
                                    </div>

                                    {/* Zoom */}
                                    <div className="flex items-center gap-1 bg-white/10 rounded-full px-1 py-0.5 ml-auto text-xs">
                                        <button onClick={() => changeScale(-0.2)} className="px-2 py-1 active:bg-white/20 rounded-full">–</button>
                                        <span className="px-2 tabular-nums w-10 text-center select-none">{Math.round(scale * 100)}%</span>
                                        <button onClick={() => changeScale(0.2)} className="px-2 py-1 active:bg-white/20 rounded-full">+</button>
                                    </div>

                                    {/* Fullscreen - keeps all features (zoom, nav, search, scroll) */}
                                    <button 
                                        onClick={toggleFullscreen} 
                                        className="px-3 py-1 text-xs bg-white/10 hover:bg-white/20 rounded-full active:bg-white/30"
                                        title="Полноэкранный режим"
                                    >
                                        ⛶
                                    </button>
                                </div>

                                {/* Scrollable viewer area.
                                    When zoomed the canvas becomes larger than the viewport.
                                    The outer div provides native scroll/pan (wheel + touch drag on mobile).
                                    This fixes "cannot see content that doesn't fit after zoom". */}
                                {/* Scroll container: allows panning the zoomed document.
                                    The inner wrapper sizes exactly to the zoomed page so overflow works.
                                    On iOS we use native scrolling for panning when zoomed. */}
                                <div
                                    ref={pdfContainerRef}
                                    className="flex-1 bg-[#111] overflow-auto p-3 touch-none select-none"
                                    style={{ minHeight: '50vh', WebkitOverflowScrolling: 'touch' }}
                                >
                                    {isLoadingPdf && <div className="p-8 text-zinc-400 text-sm">Загрузка документа...</div>}
                                    {loadError && <div className="p-4 text-red-400 text-sm">{loadError}</div>}
                                    {/* Wrapper sized exactly to the zoomed document so the scroll container can pan it */}
                                    <div 
                                        className="mx-auto bg-white/5"
                                        style={{ 
                                            width: `${displayWidth || 0}px`, 
                                            height: `${displayHeight || 0}px`,
                                            minWidth: displayWidth > 0 ? `${displayWidth}px` : '100%',
                                            minHeight: displayHeight > 0 ? `${displayHeight}px` : '100%'
                                        }}
                                    >
                                        <canvas
                                            ref={canvasRef}
                                            className="shadow-2xl bg-white block"
                                            style={{ imageRendering: scale > 2 ? 'pixelated' : 'auto' }}
                                            onContextMenu={e => e.preventDefault()}
                                            onSelectStart={e => e.preventDefault()}
                                        />
                                    </div>
                                </div>

                                {/* Beautiful search results list */}
                                {searchMatches.length > 0 && (
                                    <div className="border-t border-white/10 bg-zinc-950/80 p-3 max-h-[140px] overflow-auto text-sm">
                                        <div className="flex items-center justify-between mb-2 px-1 text-orange-400 text-xs font-medium">
                                            <span>Результаты поиска ({searchMatches.length})</span>
                                            <button 
                                                onClick={() => { setDocSearchTerm(''); setSearchMatches([]); }}
                                                className="text-zinc-400 hover:text-white"
                                            >
                                                Очистить
                                            </button>
                                        </div>
                                        <div className="space-y-1">
                                            {searchMatches.slice(0, 12).map((m, idx) => (
                                                <button
                                                    key={idx}
                                                    onClick={() => setCurrentPage(m.page)}
                                                    className="w-full text-left px-3 py-1.5 bg-white/5 hover:bg-white/10 rounded-xl flex items-start gap-3 text-sm transition-colors"
                                                >
                                                    <span className="shrink-0 mt-0.5 px-2 py-0.5 text-[10px] font-mono bg-orange-500/20 text-orange-400 rounded">
                                                        стр. {m.page}
                                                    </span>
                                                    <span className="text-zinc-200 leading-snug line-clamp-2">
                                                        {m.snippet}
                                                    </span>
                                                </button>
                                            ))}
                                        </div>
                                    </div>
                                )}
                            </div>

                            {/* QUIZ */}
                            {selectedDocument.quiz && (

                                <div className="absolute bottom-6 right-6">

                                    <button
                                        onClick={() =>
                                            window.location.href = `/quiz/${selectedDocument.quiz.id}`
                                        }
                                        className="flex items-center gap-3 rounded-2xl bg-orange-500 px-6 py-4 font-bold hover:bg-orange-600"
                                    >
                                        <PlayCircle className="h-5 w-5" />
                                        Пройти тест
                                    </button>

                                </div>

                            )}

                        </motion.div>

                    </motion.div>

                )}

            </AnimatePresence>

        </section>
    );
}
