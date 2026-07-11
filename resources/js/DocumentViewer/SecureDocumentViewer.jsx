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
    const canvasRef = useRef(null);
    const pdfContainerRef = useRef(null);
    const touchStartX = useRef(0);

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
            return;
        }

        let cancelled = false;

        (async () => {
            try {
                // Ensure PDF.js is loaded (same CDN as NaryadViewer for consistency)
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

                // Fetch the PDF as ArrayBuffer so we never expose a direct downloadable URL to the user
                const response = await fetch(selectedDocument.url);
                const arrayBuffer = await response.arrayBuffer();

                const pdf = await pdfjs.getDocument({ data: arrayBuffer }).promise;

                if (!cancelled) {
                    setPdfDoc(pdf);
                    setNumPages(pdf.numPages);
                    setCurrentPage(1);
                    setScale(1.5);
                }
            } catch (err) {
                console.error('Failed to load PDF with PDF.js:', err);
                // Fallback could be added, but for protected viewer we prefer failure over download
            }
        })();

        return () => { cancelled = true; };
    }, [selectedDocument]);

    // Render current page to canvas
    useEffect(() => {
        if (!pdfDoc || !canvasRef.current) return;

        (async () => {
            try {
                const page = await pdfDoc.getPage(currentPage);
                const viewport = page.getViewport({ scale });
                const canvas = canvasRef.current;
                const ctx = canvas.getContext('2d', { alpha: true });

                canvas.width = viewport.width;
                canvas.height = viewport.height;

                await page.render({ canvasContext: ctx, viewport }).promise;
            } catch (err) {
                console.error('PDF render error:', err);
            }
        })();
    }, [pdfDoc, currentPage, scale]);

    // Touch swipe for page navigation (similar to NaryadViewer)
    useEffect(() => {
        const el = pdfContainerRef.current;
        if (!el || !pdfDoc) return;

        const onTouchStart = (e) => {
            touchStartX.current = e.touches[0].clientX;
        };

        const onTouchEnd = (e) => {
            if (!pdfDoc || e.touches.length > 0) return;
            const endX = e.changedTouches[0].clientX;
            const delta = endX - touchStartX.current;

            if (Math.abs(delta) > 60) {
                if (delta > 0) {
                    setCurrentPage(p => Math.max(1, p - 1));
                } else {
                    setCurrentPage(p => Math.min(numPages, p + 1));
                }
            }
        };

        el.addEventListener('touchstart', onTouchStart, { passive: true });
        el.addEventListener('touchend', onTouchEnd, { passive: true });

        return () => {
            el.removeEventListener('touchstart', onTouchStart);
            el.removeEventListener('touchend', onTouchEnd);
        };
    }, [pdfDoc, currentPage, numPages]);

    const changePage = (delta) => {
        setCurrentPage(p => Math.max(1, Math.min(numPages, p + delta)));
    };

    const changeScale = (delta) => {
        setScale(s => Math.max(0.6, Math.min(3.5, s + delta)));
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
                            <div
                                ref={pdfContainerRef}
                                className="flex-1 bg-[#111] overflow-auto flex justify-center items-start p-2 touch-none"
                                style={{ minHeight: '60vh' }}
                            >
                                {!pdfDoc && (
                                    <div className="flex items-center justify-center h-full text-zinc-400">
                                        Загрузка документа...
                                    </div>
                                )}

                                <div className="relative">
                                    <canvas
                                        ref={canvasRef}
                                        className="max-w-full shadow-2xl bg-white"
                                        onContextMenu={(e) => e.preventDefault()}
                                        onDragStart={(e) => e.preventDefault()}
                                    />

                                    {/* Управление без PDF.js toolbar (нельзя скачать) */}
                                    {pdfDoc && (
                                        <div className="absolute bottom-3 left-1/2 -translate-x-1/2 flex items-center gap-2 bg-black/80 text-white px-3 py-1.5 rounded-full text-sm backdrop-blur border border-white/10">
                                            <button
                                                onClick={() => changePage(-1)}
                                                disabled={currentPage <= 1}
                                                className="px-3 py-1 disabled:opacity-30 active:bg-white/10 rounded"
                                            >
                                                ←
                                            </button>
                                            <span className="tabular-nums px-2 select-none">
                                                {currentPage} / {numPages}
                                            </span>
                                            <button
                                                onClick={() => changePage(1)}
                                                disabled={currentPage >= numPages}
                                                className="px-3 py-1 disabled:opacity-30 active:bg-white/10 rounded"
                                            >
                                                →
                                            </button>

                                            <div className="w-px h-4 bg-white/20 mx-1" />

                                            <button
                                                onClick={() => changeScale(-0.2)}
                                                className="px-2 py-0.5 active:bg-white/10 rounded"
                                            >
                                                –
                                            </button>
                                            <span className="tabular-nums w-[52px] text-center select-none">
                                                {Math.round(scale * 100)}%
                                            </span>
                                            <button
                                                onClick={() => changeScale(0.2)}
                                                className="px-2 py-0.5 active:bg-white/10 rounded"
                                            >
                                                +
                                            </button>
                                        </div>
                                    )}
                                </div>
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
