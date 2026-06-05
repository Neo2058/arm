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
import React, { useEffect, useMemo, useState } from 'react';
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
            document.body.style.overflow = 'hidden';
        } else {
            document.body.style.overflow = '';
        }

        window.addEventListener('keydown', handleEsc);

        return () => {
            window.removeEventListener('keydown', handleEsc);
            document.body.style.overflow = '';
        };

    }, [selectedDocument]);

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
        <section className="relative min-h-screen overflow-hidden bg-[#0b1018] text-white">

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
                                        Secure viewer
                                    </div>
                                </div>

                                <button
                                    onClick={() => setSelectedDocument(null)}
                                    className="rounded-xl border border-white/10 bg-white/5 p-3 hover:bg-white/10"
                                >
                                    <X className="h-5 w-5" />
                                </button>

                            </div>

                            {/* CONTENT */}
                            <div className="flex-1 bg-black">

                                <iframe
                                    src={`${selectedDocument.url}#toolbar=0`}
                                    className="h-full w-full border-none"
                                    title={selectedDocument.title}
                                />

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
