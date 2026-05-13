export const imagesArray = [
    {
        name: "Техническая учёба",
        src: "/images/professionalDriver.jpg",
        role: ['super_admin', 'instructor', 'driver', 'admin'],
        link: '/index',
        details: [
            {
                name: "Тесты",
            },
            {
                name: "Конспекты",
            },
            {
                name: "Задать вопрос",
            },
        ],
    },
    {
        name: "Тех кабинет",
        src: "/images/professionalDriver.jpg",
        role: ['super_admin', 'student', 'instructor'],
        link: '/admin',
        details: [
            {
                name: "Тесты",
            },
            {
                name: "Конспекты",
            },
            {
                name: "Чат с бригадами",
            },
        ],
    },
    {
        name: "Росписи",
        src: "/watch2.jpeg",
        role: ['super_admin', 'student', 'driver', 'admin'],
        logo: "/assets/materials.svg",
        details: [
            {
                name: "Текущие инструктажи",
            },
            {
                name: "Папка БД",
            },
        ],
    },
    {
        name: "Записи в формуляр",
        src: "/watch18.jpeg",
        role: ['super_admin', 'student', 'instructor', 'driver', 'admin'],
        logo: "/assets/design.svg",
        details: [
            {
                name: "Проезды светофоров",
            },
            {
                name: "Проезды станций",
            },
            {
                name: "Прочие нарушения",
            },
        ],
    },
    {
        name: "Руководящие документы",
        src: "/watch19.jpeg",
        role: ['super_admin', 'student', 'instructor', 'driver', 'admin'],
        logo: "/assets/features.svg",
        details: [
            {
                name: "Инструктажи по депо",
            },
            {
                name: "Инструктажи по метрополитену",
            },
            {
                name: "Приказы по депо",
            },
            {
                name: "Приказы по метрополитену",
            },
        ],
    },
    {
        name: "Наряды",
        src: "/watch5.jpeg",
        role: ['super_admin', 'student', 'instructor', 'driver', 'admin'],
        logo: "/assets/limited.svg",
        details: [
            {
                name: "Текущие наряды",
            },
            {
                name: "Разбивки",
            },
            {
                name: "",
            },
        ],
    },
    {
        name: "Подстройка смен",
        src: "/watch16.jpeg",
        role: ['super_admin', 'driver', 'admin'],
        logo: "/assets/warranty.svg",
        details: [
            {
                //
            },
        ],
    },
    {
        name: "Подстройка смен",
        src: "/watch16.jpeg",
        role: ['super_admin', 'student', 'instructor', 'admin'],
        logo: "/assets/warranty.svg",
        details: [
            {
               //
            },
        ],
    },
    {
        name: "Нарядчики",
        src: "/watch20.jpeg",
        role: ['naryadchik'],
        logo: "/assets/packaging.svg",
        details: [
            {
                name: "Составление наряда",
            },
            {
                name: "Печать наряда",
            },
            {
                name: "",
            },
        ],
    },
];


export const imageVariants = {
    center: { x: "0%", scale: 1, zIndex: 7 },
    left1: { x: "-30%", scale: 0.7, zIndex: 5 },
    left2: { x: "-60%", scale: 0.5, zIndex: 4 },
    left3: { x: "-80%", scale: 0.3, zIndex: 3 },
    left4: { x: "-95%", scale: 0.2, zIndex: 2 },
    right4: { x: "95%", scale: 0.2, zIndex: 1 },
    right3: { x: "80%", scale: 0.3, zIndex: 3 },
    right2: { x: "60%", scale: 0.5, zIndex: 4 },
    right1: { x: "30%", scale: 0.7, zIndex: 5 },
};


export const positions = [
    "center",
    "left1",
    "left2",
    "left3",
    "right3",
    "right2",
    "right1",
];
