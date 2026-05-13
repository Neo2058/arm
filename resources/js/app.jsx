import './bootstrap';

import React from 'react';
import { createRoot } from 'react-dom/client';
import Carousel from './Carousel/Carousel.jsx'; // Проверь путь к файлу
import CountdownTimer from "./CountDownTimer/CountDownTimer.jsx";
import SecureDocumentViewer from "./DocumentViewer/SecureDocumentViewer.jsx";
import QuizPlayer from './QuizPlayer/QuizPlayer';

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
    { documents: window.__DOCUMENTS_DATA__ || [] }
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


