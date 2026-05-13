import React, { useState, useEffect } from 'react';
import { motion, AnimatePresence } from 'framer-motion';
import { Clock, CheckCircle2, ChevronRight } from 'lucide-react';
import axios from 'axios'; // 1. Не забудь добавить импорт

export default function QuizPlayer({ quiz }) {
    const [currentQuestionIndex, setCurrentQuestionIndex] = useState(0);
    const [selectedAnswers, setSelectedAnswers] = useState({});
    const [isFinished, setIsFinished] = useState(false);
    const [isSubmitting, setIsSubmitting] = useState(false); // Для индикации загрузки
    const [timeLeft, setTimeLeft] = useState(quiz.time_limit * 60);

    // 2. Функция расчета баллов
    const calculateScore = () => {
        let score = 0;
        quiz.questions.forEach(question => {
            const selectedId = selectedAnswers[question.id];
            const correctOption = question.answers.find(a => a.is_correct);
            if (correctOption && selectedId === correctOption.id) {
                score++;
            }
        });
        return score;
    };

    // 3. Функция отправки результатов
    const finishTest = async () => {
        if (isSubmitting) return;
        setIsSubmitting(true);

        const resultsData = {
            quiz_id: quiz.id,
            score: calculateScore(),
            total_questions: quiz.questions.length,
            time_spent: quiz.time_limit * 60 - timeLeft,
            answers_log: selectedAnswers
        };

        try {
            // Laravel сам подтянет CSRF из кук, если axios настроен стандартно
            await axios.post('/api/quiz-results', resultsData);
            setIsFinished(true);
        } catch (error) {
            console.error("Ошибка сохранения результатов", error);
            alert("Не удалось сохранить результат. Попробуйте еще раз.");
        } finally {
            setIsSubmitting(false);
        }
    };

    // Таймер
    useEffect(() => {
        if (timeLeft <= 0) {
            finishTest(); // Автоматически завершаем при выходе времени
            return;
        }
        const timer = setInterval(() => setTimeLeft(prev => prev - 1), 1000);
        return () => clearInterval(timer);
    }, [timeLeft]);

    const currentQuestion = quiz.questions[currentQuestionIndex];

    const handleAnswerSelect = (answerId) => {
        setSelectedAnswers({ ...selectedAnswers, [currentQuestion.id]: answerId });
    };

    const handleNext = () => {
        if (currentQuestionIndex < quiz.questions.length - 1) {
            setCurrentQuestionIndex(prev => prev + 1);
        } else {
            finishTest(); // Заменяем setIsFinished(true) на нашу функцию
        }
    };

    if (isFinished) {
        return (
            <div className="min-h-screen bg-[#0b1018] flex items-center justify-center p-4">
                <div className="max-w-md w-full bg-white/5 border border-white/10 p-8 rounded-3xl text-center">
                    <CheckCircle2 className="w-16 h-16 text-green-500 mx-auto mb-4" />
                    <h2 className="text-3xl font-bold text-white mb-2">Тест завершен!</h2>
                    <p className="text-zinc-400 mb-6">Ваши ответы отправлены на проверку инструктору.</p>
                    <button
                        onClick={() => window.location.href = '/mainMenu'}
                        className="w-full py-4 bg-orange-500 rounded-2xl font-bold text-white"
                    >
                        Вернуться в меню
                    </button>
                </div>
            </div>
        );
    }

    return (
        <div className="min-h-screen bg-[#0b1018] text-white p-6">
            <div className="max-w-3xl mx-auto">
                <div className="flex justify-between items-center mb-8">
                    <h1 className="text-xl font-bold text-orange-400">{quiz.title}</h1>
                    <div className="flex items-center gap-2 bg-white/5 px-4 py-2 rounded-xl border border-white/10">
                        <Clock className="w-4 h-4" />
                        <span className="font-mono">
                            {Math.floor(timeLeft / 60)}:{(timeLeft % 60).toString().padStart(2, '0')}
                        </span>
                    </div>
                </div>

                <div className="h-1 w-full bg-white/5 rounded-full mb-12">
                    <div
                        className="h-full bg-orange-500 transition-all duration-300"
                        style={{ width: `${((currentQuestionIndex + 1) / quiz.questions.length) * 100}%` }}
                    />
                </div>

                <AnimatePresence mode="wait">
                    <motion.div
                        key={currentQuestion.id}
                        initial={{ opacity: 0, x: 20 }}
                        animate={{ opacity: 1, x: 0 }}
                        exit={{ opacity: 0, x: -20 }}
                    >
                        <span className="text-zinc-500 text-sm mb-2 block">Вопрос {currentQuestionIndex + 1} из {quiz.questions.length}</span>
                        <h2 className="text-2xl font-semibold mb-8">{currentQuestion.question_text}</h2>

                        <div className="space-y-4">
                            {currentQuestion.answers.map((answer) => (
                                <button
                                    key={answer.id}
                                    onClick={() => handleAnswerSelect(answer.id)}
                                    className={`w-full p-6 rounded-2xl text-left border transition-all ${
                                        selectedAnswers[currentQuestion.id] === answer.id
                                            ? 'border-orange-500 bg-orange-500/10'
                                            : 'border-white/10 bg-white/5 hover:bg-white/10'
                                    }`}
                                >
                                    {answer.answer_text}
                                </button>
                            ))}
                        </div>
                    </motion.div>
                </AnimatePresence>

                <button
                    disabled={!selectedAnswers[currentQuestion.id] || isSubmitting}
                    onClick={handleNext}
                    className="mt-12 w-full py-4 bg-white text-black rounded-2xl font-bold disabled:opacity-50 flex items-center justify-center gap-2"
                >
                    {isSubmitting ? 'Сохранение...' : (currentQuestionIndex === quiz.questions.length - 1 ? 'Завершить тест' : 'Далее')}
                    {!isSubmitting && <ChevronRight className="w-5 h-5" />}
                </button>
            </div>
        </div>
    );
}
