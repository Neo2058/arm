import React, { useState, useEffect } from 'react';
import { motion, AnimatePresence } from 'framer-motion';
import { Clock, BookOpen, X, ChevronRight, CheckCircle2 } from 'lucide-react';
import axios from 'axios';

export default function GeneralQuizPlayer({ quiz }) {
    const [currentQuestionIndex, setCurrentQuestionIndex] = useState(0);
    const [selectedAnswers, setSelectedAnswers] = useState({});
    const [isFinished, setIsFinished] = useState(false);
    const [timeLeft, setTimeLeft] = useState(quiz.time_limit * 60);

    // Стейт для модального окна просмотра документа
    const [activePdf, setActivePdf] = useState(null); // { url, page, title }

    useEffect(() => {
        if (timeLeft <= 0) { handleFinish(); return; }
        const timer = setInterval(() => setTimeLeft(prev => prev - 1), 1000);
        return () => clearInterval(timer);
    }, [timeLeft]);

    const currentQuestion = quiz.questions[currentQuestionIndex];

    const handleFinish = async () => {
        // Подсчет баллов
        let score = 0;
        quiz.questions.forEach(q => {
            const correct = q.answers.find(a => a.is_correct || a.is_correct == 1);
            if (correct && selectedAnswers[q.id] === correct.id) score++;
        });

        try {
            await axios.post('/api/quiz-results', {
                quiz_id: quiz.id,
                score: score,
                total_questions: quiz.questions.length,
                time_spent: quiz.time_limit * 60 - timeLeft,
                answers_log: selectedAnswers
            });
            setIsFinished(true);
        } catch (e) {
            console.error(e);
        }
    };

    if (isFinished) return (
        <div className="min-h-screen bg-[#0b1018] flex items-center justify-center text-white text-center p-4">
            <div className="bg-white/5 border border-white/10 p-8 rounded-3xl max-w-md w-full">
                <CheckCircle2 className="w-16 h-16 text-green-500 mx-auto mb-4" />
                <h2 className="text-2xl font-bold mb-4">Аттестация завершена!</h2>
                <button onClick={() => window.location.href = '/mainMenu'} className="px-6 py-3 bg-orange-500 rounded-xl font-bold">В главное меню</button>
            </div>
        </div>
    );

    return (
        <div className="min-h-screen bg-[#0b1018] text-white p-6 relative">
            <div className="max-w-4xl mx-auto">
                {/* Шапка тестера */}
                <div className="flex justify-between items-center mb-6">
                    <h1 className="text-xl font-bold text-orange-400">{quiz.title}</h1>
                    <div className="flex items-center gap-2 bg-white/5 px-4 py-2 rounded-xl border border-white/10 font-mono">
                        <Clock className="w-4 h-4" />
                        {Math.floor(timeLeft / 60)}:{(timeLeft % 60).toString().padStart(2, '0')}
                    </div>
                </div>

                {/* Вопрос и Подсказки-Ссылки */}
                <div className="bg-white/5 border border-white/10 p-8 rounded-3xl mb-6">
                    <span className="text-zinc-500 text-sm block mb-2">Вопрос {currentQuestionIndex + 1} из {quiz.questions.length}</span>
                    <h2 className="text-2xl font-medium mb-6">{currentQuestion.question_text}</h2>

                    {/* Вывод перекрестных ссылок на PDF */}
                    {currentQuestion.references && currentQuestion.references.length > 0 && (
                        <div className="mb-6 flex flex-wrap gap-3 p-4 bg-orange-500/5 rounded-2xl border border-orange-500/10">
                            <span className="text-xs text-orange-300 w-full flex items-center gap-1">
                                <BookOpen className="w-3.5 h-3.5" /> Использовать первоисточник (подсказка):
                            </span>
                            {currentQuestion.references.map((ref, idx) => (
                                <button
                                    key={idx}
                                    onClick={() => setActivePdf({ url: ref.url, page: ref.page, title: ref.anchor_text })}
                                    className="text-xs bg-white/5 hover:bg-orange-500/20 border border-white/10 hover:border-orange-500/30 px-3 py-1.5 rounded-xl transition flex items-center gap-1 text-zinc-300 hover:text-white"
                                >
                                    {ref.anchor_text} (стр. {ref.page})
                                </button>
                            ))}
                        </div>
                    )}

                    {/* Ответы */}
                    <div className="space-y-3">
                        {currentQuestion.answers.map(ans => (
                            <button
                                key={ans.id}
                                onClick={() => setSelectedAnswers({...selectedAnswers, [currentQuestion.id]: ans.id})}
                                className={`w-full p-5 text-left rounded-2xl border transition-all ${
                                    selectedAnswers[currentQuestion.id] === ans.id ? 'border-orange-500 bg-orange-500/10' : 'border-white/10 bg-white/5 hover:bg-white/10'
                                }`}
                            >
                                {ans.answer_text}
                            </button>
                        ))}
                    </div>
                </div>

                <button
                    disabled={!selectedAnswers[currentQuestion.id]}
                    onClick={() => currentQuestionIndex < quiz.questions.length - 1 ? setCurrentQuestionIndex(c => c + 1) : handleFinish()}
                    className="w-full py-4 bg-white text-black font-bold rounded-2xl flex items-center justify-center gap-2 disabled:opacity-50"
                >
                    {currentQuestionIndex === quiz.questions.length - 1 ? 'Завершить аттестацию' : 'Следующий вопрос'}
                    <ChevronRight className="w-4 h-4" />
                </button>
            </div>

            {/* Слой МОДАЛЬНОГО ОКНА ДЛЯ PDF (Встроенный защищенный просмотрщик) */}
            <AnimatePresence>
                {activePdf && (
                    <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }} className="fixed inset-0 z-50 bg-black/80 backdrop-blur-md flex items-center justify-center p-4">
                        <motion.div initial={{ scale: 0.95, y: 20 }} animate={{ scale: 1, y: 0 }} exit={{ scale: 0.95, y: 20 }} className="bg-[#0b1018] w-full max-w-5xl h-[85vh] rounded-3xl border border-white/10 overflow-hidden flex flex-col">
                            <div className="p-4 border-b border-white/10 flex justify-between items-center bg-white/5">
                                <span className="font-semibold text-orange-400">Справочный материал: {activePdf.title}</span>
                                <button onClick={() => setActivePdf(null)} className="p-2 bg-white/5 hover:bg-white/10 rounded-xl border border-white/10 transition">
                                    <X className="w-5 h-5" />
                                </button>
                            </div>
                            <div className="flex-1 relative">
                                {/* Защитный слой от кликов */}
                                <div className="absolute inset-0 z-20" onContextMenu={e => e.preventDefault()} />
                                <iframe
                                    // Магия: передаем #page=X, и браузер сам прокрутит PDF на нужный лист!
                                    src={`${activePdf.url}#toolbar=0&page=${activePdf.page}`}
                                    className="w-full h-full border-none relative z-10"
                                />
                            </div>
                        </motion.div>
                    </motion.div>
                )}
            </AnimatePresence>
        </div>
    );
}
