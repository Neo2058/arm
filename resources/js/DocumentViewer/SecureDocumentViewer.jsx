import React, { useEffect, useMemo, useState } from 'react';
import { motion, AnimatePresence } from 'framer-motion';
import {
    FileText,
    Search,
    Shield,
    ArrowLeft,
    Eye,
    Clock3,
    Lock,
    X, PlayCircle,
} from 'lucide-react';

export default function SecureDocumentViewer({
                                                 documents = [],
                                                 onBack,
                                             }) {
    const [selectedDocument, setSelectedDocument] = useState(null);
    const [search, setSearch] = useState('');

    useEffect(() => {
        const disableActions = (e) => {
            if (
                (e.ctrlKey || e.metaKey) &&
                ['s', 'p', 'u'].includes(e.key.toLowerCase())
            ) {
                e.preventDefault();
            }
        };

        const disableContext = (e) => {
            e.preventDefault();
        };

        document.addEventListener('keydown', disableActions);
        document.addEventListener('contextmenu', disableContext);

        return () => {
            document.removeEventListener('keydown', disableActions);
            document.removeEventListener('contextmenu', disableContext);
        };
    }, []);

    const filteredDocuments = useMemo(() => {
        return documents.filter((doc) =>
            doc.title.toLowerCase().includes(search.toLowerCase())
        );
    }, [documents, search]);

    return (
        <section className="relative min-h-screen overflow-hidden bg-[#0b1018] text-white">
            {/* Background */}
            <div className="absolute inset-0 bg-[radial-gradient(circle_at_top,rgba(255,140,0,0.15),transparent_40%)]" />
            <div className="absolute inset-0 bg-[linear-gradient(to_bottom,rgba(255,255,255,0.02),transparent)]" />

            <div className="relative z-10 mx-auto flex max-w-7xl flex-col gap-6 px-4 py-6 lg:flex-row lg:px-8">

                {/* Sidebar */}
                <motion.aside
                    initial={{ opacity: 0, x: -30 }}
                    animate={{ opacity: 1, x: 0 }}
                    transition={{ duration: 0.5 }}
                    className="w-full rounded-3xl border border-white/10 bg-white/5 backdrop-blur-xl lg:w-[380px]"
                >
                    {/* Header */}
                    <div className="border-b border-white/10 p-6">
                        <div className="mb-4 flex items-center justify-between">
                            <div>
                                <div className="mb-2 flex items-center gap-2 text-orange-300">
                                    <Shield className="h-5 w-5" />
                                    <span className="text-xs uppercase tracking-[0.25em]">
                    Защищённый доступ
                  </span>
                                </div>

                                <h1 className="text-2xl font-black md:text-3xl">
                                    Документы
                                </h1>
                            </div>

                            {onBack && (
                                <button
                                    onClick={onBack}
                                    className="rounded-xl border border-white/10 bg-white/5 p-3 transition hover:bg-white/10"
                                >
                                    <ArrowLeft className="h-5 w-5" />
                                </button>
                            )}
                        </div>

                        {/* Search */}
                        <div className="relative">
                            <Search className="absolute left-4 top-1/2 h-4 w-4 -translate-y-1/2 text-zinc-400" />

                            <input
                                type="text"
                                placeholder="Поиск документа..."
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                className="h-12 w-full rounded-2xl border border-white/10 bg-black/20 pl-11 pr-4 text-sm text-white outline-none transition focus:border-orange-400/40"
                            />
                        </div>
                    </div>

                    {/* Documents */}
                    <div className="max-h-[70vh] overflow-y-auto p-4">
                        <div className="space-y-3">
                            {filteredDocuments.map((doc, index) => {
                                const active = selectedDocument?.id === doc.id;

                                return (
                                    <motion.button
                                        key={doc.id}
                                        initial={{ opacity: 0, y: 10 }}
                                        animate={{ opacity: 1, y: 0 }}
                                        transition={{ delay: index * 0.03 }}
                                        onClick={() => setSelectedDocument(doc)}
                                        className={`group relative w-full overflow-hidden rounded-2xl border p-4 text-left transition ${
                                            active
                                                ? 'border-orange-400/40 bg-orange-400/10'
                                                : 'border-white/10 bg-white/[0.03] hover:bg-white/[0.07]'
                                        }`}
                                    >
                                        <div className="absolute inset-0 bg-gradient-to-r from-white/5 to-transparent opacity-0 transition group-hover:opacity-100" />

                                        <div className="relative flex items-start gap-4">
                                            <div className="rounded-xl bg-orange-400/10 p-3 text-orange-300">
                                                <FileText className="h-6 w-6" />
                                            </div>

                                            <div className="min-w-0 flex-1">
                                                <h2 className="truncate text-base font-semibold text-white">
                                                    {doc.title}
                                                </h2>

                                                <div className="mt-2 flex flex-wrap items-center gap-3 text-xs text-zinc-400">
                                                    <div className="flex items-center gap-1">
                                                        <Clock3 className="h-3.5 w-3.5" />
                                                        {doc.updatedAt}
                                                    </div>

                                                    <div className="flex items-center gap-1 text-orange-300">
                                                        <Lock className="h-3.5 w-3.5" />
                                                        Только чтение
                                                    </div>
                                                </div>
                                            </div>

                                            <Eye className="h-5 w-5 text-zinc-500 transition group-hover:text-white" />
                                        </div>

                                        {doc.quiz && (
                                            <div className="mt-2 flex items-center gap-1 text-cyan-400 font-bold text-[10px] uppercase tracking-wider">
                                                <div className="h-1.5 w-1.5 rounded-full bg-cyan-400 animate-pulse" />
                                                Доступен тест
                                            </div>
                                        )}
                                    </motion.button>
                                );
                            })}
                        </div>
                    </div>
                </motion.aside>

                {/* Viewer */}
                <motion.main
                    initial={{ opacity: 0, y: 20 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: 0.6 }}
                    className="flex-1 overflow-hidden rounded-3xl border border-white/10 bg-white/5 backdrop-blur-xl"
                >
                    <AnimatePresence mode="wait">
                        {selectedDocument ? (
                            <motion.div
                                key={selectedDocument.id}
                                initial={{ opacity: 0, scale: 0.98 }}
                                animate={{ opacity: 1, scale: 1 }}
                                exit={{ opacity: 0, scale: 0.98 }}
                                transition={{ duration: 0.25 }}
                                className="flex h-full flex-col"
                            >
                                {/* Viewer Header */}
                                <div className="flex items-center justify-between border-b border-white/10 px-5 py-4">
                                    <div>
                                        <h2 className="text-lg font-bold text-white md:text-2xl">
                                            {selectedDocument.title}
                                        </h2>

                                        <div className="mt-1 flex items-center gap-2 text-xs uppercase tracking-[0.2em] text-zinc-400">
                                            <Shield className="h-3.5 w-3.5" />
                                            Protected document mode
                                        </div>
                                    </div>

                                    <button
                                        onClick={() => setSelectedDocument(null)}
                                        className="rounded-xl border border-white/10 bg-white/5 p-3 transition hover:bg-white/10"
                                    >
                                        <X className="h-5 w-5" />
                                    </button>
                                </div>

                                {selectedDocument.quiz && (
                                    <motion.div
                                        initial={{ y: 20, opacity: 0 }}
                                        animate={{ y: 0, opacity: 1 }}
                                        className="absolute bottom-8 right-8 z-30"
                                    >
                                        <button
                                            onClick={() => window.location.href = `/quiz/${selectedDocument.quiz.id}`}
                                            className="flex items-center gap-3 px-6 py-3 bg-orange-500 hover:bg-orange-600 text-white rounded-2xl shadow-2xl transition-transform hover:scale-105 font-bold"
                                        >
                                            <PlayCircle className="h-5 w-5" />
                                            Пройти тест по документу
                                        </button>
                                    </motion.div>
                                )}

                                {/* Viewer */}
                                <div className="relative h-[80vh] overflow-y-auto bg-black">
                                    {/* Protection Layer */}
                                    <div
                                        className="absolute inset-0 z-10"
                                        onContextMenu={(e) => e.preventDefault()}
                                    />

                                    {/* PDF */}
                                    <iframe
                                        src={`${selectedDocument.url}#toolbar=0`}
                                        className="h-[2000px] w-full border-none"
                                        title={selectedDocument.title}
                                    />

                                    {/* Bottom overlay */}
                                    <div className="pointer-events-none absolute bottom-0 left-0 right-0 z-20 flex items-center justify-between border-t border-white/10 bg-black/70 px-5 py-3 backdrop-blur-md">
                                        <div className="flex items-center gap-2 text-xs uppercase tracking-[0.2em] text-zinc-400">
                                            <Lock className="h-3.5 w-3.5" />
                                            Download disabled
                                        </div>

                                        <div className="text-xs text-zinc-500">
                                            Secure locomotive documentation access
                                        </div>
                                    </div>
                                </div>
                            </motion.div>
                        ) : (
                            <motion.div
                                initial={{ opacity: 0 }}
                                animate={{ opacity: 1 }}
                                exit={{ opacity: 0 }}
                                className="flex h-[85vh] flex-col items-center justify-center px-6 text-center"
                            >
                                <div className="rounded-3xl border border-white/10 bg-white/5 p-8 backdrop-blur-lg">
                                    <div className="mx-auto mb-6 flex h-20 w-20 items-center justify-center rounded-full bg-orange-400/10 text-orange-300">
                                        <FileText className="h-10 w-10" />
                                    </div>

                                    <h2 className="text-2xl font-black text-white md:text-4xl">
                                        Выберите документ
                                    </h2>

                                    <p className="mx-auto mt-4 max-w-lg text-sm leading-relaxed text-zinc-400 md:text-base">
                                        Доступ к документации осуществляется в защищённом режиме.
                                        Скачивание, печать и копирование контента ограничены.
                                    </p>
                                </div>
                            </motion.div>
                        )}
                    </AnimatePresence>
                </motion.main>
            </div>
        </section>
    );
}
