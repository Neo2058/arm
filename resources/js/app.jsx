import './bootstrap';

import React from 'react';
import { createRoot } from 'react-dom/client';
import Carousel from './Carousel/Carousel.jsx'; // Проверь путь к файлу
import CountdownTimer from "./CountDownTimer/CountDownTimer.jsx";
import SecureDocumentViewer from "./DocumentViewer/SecureDocumentViewer.jsx";
import NaryadViewer from "./NaryadViewer/NaryadViewer.jsx";
import QuizPlayer from './QuizPlayer/QuizPlayer';
import GeneralQuizPlayer from './GeneralQuizPlayer/GeneralQuizPlayer';
import WorkCalendar from './WorkCalendar/WorkCalendar';


const mountComponent = (id, Component, props = {}) => {

    const element = document.getElementById(id);

    if (!element) return;

    const root = createRoot(element);

    root.render(<Component {...props} />);
};


// Carousel
mountComponent(
    'carousel-menu',
    Carousel,
    {
        role: document
            .getElementById('carousel-menu')
            ?.getAttribute('data-role')
    }
);

// Countdown
mountComponent(
    'count-down-timer',
    CountdownTimer
);

mountComponent(
    'document-viewer',
    SecureDocumentViewer,
    { 
        categories: window.__DOCUMENTS_TREE__ || [],
        deviceOs: window.__DEVICE_OS__ || 'other'
    }
);

mountComponent(
    'naryad-viewer',
    NaryadViewer,
    { 
        naryads: window.__NARYADS__ || [],
        isAdmin: window.__IS_ADMIN__ ?? false
    }
);
// Монтируем личный кабинет календаря смен машиниста
mountComponent(
    'work-calendar-root',
    WorkCalendar
);


// Просто берем данные из глобального окна
const quizData = window.__QUIZ_DATA__;

if (document.getElementById('quiz-player') && quizData) {
    mountComponent(
        'quiz-player',
        QuizPlayer,
        { quiz: quizData }
    );
}

const genQuizEl = document.getElementById('general-quiz-player');
if (genQuizEl) {
    // 1. Сначала ищем данные в глобальном окне (если добавим туда)
    // 2. Если их там нет, безопасно берем из data-атрибута, который Laravel @js уже сделал объектом
    const quizData = window.__GENERAL_QUIZ_DATA__ ||
        genQuizEl._livewire?.data || // на случай кеша Livewire
        genQuizEl.dataQuiz; // или просто читаем объект напрямую, если передали через пропсы

    // Самый надежный способ прочитать данные, переданные через data-аттрибут и @js:
    // Браузер видит это не как строку, а как готовое свойство, если читать его правильно.
    // Но так как мы передали через data-quiz='@js($quiz)', лучше сделать прямую привязку к окну.

    const directData = window.__QUIZ_DATA__ || window.__GENERAL_QUIZ_DATA__;

    if (directData) {
        createRoot(genQuizEl).render(<GeneralQuizPlayer quiz={directData} />);
    } else {
        // Альтернативный безопасный разбор, если данные все же пришли строкой в атрибуте
        try {
            const attrData = genQuizEl.getAttribute('data-quiz');
            // Если строка начинается с '{' или '[', то парсим, иначе это уже объект (особенность Vite/Blade)
            const parsedData = typeof attrData === 'string' && (attrData.startsWith('{') || attrData.startsWith('['))
                ? JSON.parse(attrData)
                : attrData;

            createRoot(genQuizEl).render(<GeneralQuizPlayer quiz={parsedData} />);
        } catch (e) {
            console.error("Ошибка чтения данных квиза:", e);
        }
    }
}

async function generateDeviceFingerprint() {
    if (document.cookie.includes('device_key=')) return;

    const canvas = document.createElement('canvas');
    const ctx = canvas.getContext('2d');
    ctx.textBaseline = "top";
    ctx.font = "14px 'Arial'";
    ctx.fillText("ARM-Security-Token", 2, 2);

    // Уникальная строка на основе рендеринга видеокарты конкретного устройства
    const canvasData = canvas.toDataURL();

    // Добавим параметры экрана и процессора
    const rawString = canvasData + navigator.userAgent + screen.width + screen.height + (navigator.hardwareConcurrency || 2);

    // Простейший быстрый хэш (SHA-256 или аналогичный)
    const msgBuffer = new TextEncoder().encode(rawString);
    const hashBuffer = await crypto.subtle.digest('SHA-256', msgBuffer);
    const hashArray = Array.from(new Uint8Array(hashBuffer));
    const hashHex = hashArray.map(b => b.toString(16).padStart(2, '0')).join('');

    // Записываем хэш устройства в куки на 5 лет
    document.cookie = `device_key=${hashHex}; path=/; max-age=157680000`;
}
generateDeviceFingerprint();




