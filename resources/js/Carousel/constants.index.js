export const imagesArray = [
    {
        name: "Техническая учёба",
        src: "/images/professionalDriver.jpg",
        role: ['driver', 'admin'],
        link: '/timer',
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
        role: ['super_admin', 'admin'],
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
        src: "/images/professionalDriver.jpg",
        role: ['super_admin', 'student', 'driver', 'admin'],
        logo: "/assets/materials.svg",
        link: '/rosisi',
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
        name: "Связь с разработчиком",
        src: "/images/professionalDriver.jpg",
        role: ['super_admin', 'student', 'driver', 'admin'],
        logo: "/assets/features.svg",
        link: '/backstage',
        details: [
            {
                name: "Напишите разработчику, сообщите о проблеме или поддержите проект"
            }
        ],
    },
    {
        name: "Наряды",
        src: "/images/professionalDriver.jpg",
        role: ['super_admin', 'student', 'driver', 'admin'],
        logo: "/assets/limited.svg",
        link: '/naryady',
        details: [
            {
                name: "Просмотр текущих нарядов (PDF)",
            },
            {
                name: "Поиск по фамилии с подсветкой",
            },
            {
                name: "Только просмотр, без скачивания",
            },
        ],
    },
    {
        name: "Подстройки",
        src: "/images/professionalDriver.jpg",
        role: ['super_admin', 'student', 'driver', 'admin', 'dispatcher'],
        logo: "/assets/warranty.svg",
        link: '/podstroiki',
        details: [
            {
                name: "Заявки на подстройку смен",
            },
            {
                name: "Текущий и следующий месяц",
            },
        ],
    },
    {
        name: "Учёт рабочих смен",
        src: "/images/professionalDriver.jpg",
        role: ['super_admin', 'driver'],
        logo: "/assets/warranty.svg",
        link: '/worktime',
        details: [
            {
              name: "Календарь смен и калькулятор зарплаты",
            },
        ],
    },
    {
        name: "Нарядчики",
        src: "/images/professionalDriver.jpg",
        role: ['dispatcher'],
        logo: "/assets/packaging.svg",
        link: '/naryad',
        details: [
            {
                name: "Составление наряда",
            },
            {
                name: "Печать наряда",
            },
            {
                name: "НСИ / кодификаторы",
            },
            {
                name: "Учёт / лицевые счета",
            },
        ],
    },
    {
        name: "НСИ",
        src: "/images/professionalDriver.jpg",
        role: ['dispatcher'],
        logo: "/assets/packaging.svg",
        link: '/nsi',
        details: [
            {
                name: "Кодификаторы",
            },
            {
                name: "Нормативы",
            },
            {
                name: "Настройка АРМ",
            },
        ],
    },
    {
        name: "Оператор учёта",
        src: "/images/professionalDriver.jpg",
        role: ['operator', 'dispatcher', 'admin', 'super_admin'],
        logo: "/assets/packaging.svg",
        link: '/uchet',
        details: [
            {
                name: "Учётные карточки и ЛС",
            },
            {
                name: "Выгрузка в бухгалтерию",
            },
            {
                name: "Закрытие месяца",
            },
        ],
    },
    {
        name: "Рабочий журнал ТЧМ",
        src: "/images/professionalDriver.jpg",
        role: ['instructor'],
        logo: "/assets/features.svg",
        link: '/journal',
        details: [
            {
                name: "Статистика колонны",
            },
            {
                name: "TODO и нормативы",
            },
            {
                name: "Работа с документами + ИИ-вопросы",
            },
        ],
    },
    {
        name: "Мой профиль",
        src: "/images/professionalDriver.jpg",
        link: '/account',
        details: [
            { name: "Данные сотрудника" },
            { name: "Привязка Telegram и получение ключа" },
        ],
    },
];

export const imageVariants = {
    center: { x: "0%", scale: 1, zIndex: 7, opacity: 1 },
    left1: { x: "-30%", scale: 0.7, zIndex: 5, opacity: 1 },
    left2: { x: "-60%", scale: 0.5, zIndex: 4, opacity: 1 },
    left3: { x: "-80%", scale: 0.3, zIndex: 3, opacity: 1 },
    right3: { x: "80%", scale: 0.3, zIndex: 3, opacity: 1 },
    right2: { x: "60%", scale: 0.5, zIndex: 4, opacity: 1 },
    right1: { x: "30%", scale: 0.7, zIndex: 5, opacity: 1 },
};

export const mobileVariants = {
    center: { x: "0%", scale: 1, zIndex: 5, opacity: 1 },
    left1: { x: "-14%", scale: 0.9, zIndex: 3, opacity: 0.45 },
    right1: { x: "14%", scale: 0.9, zIndex: 3, opacity: 0.45 },
};

const DESKTOP_SLOT = {
    0: 'center',
    [-1]: 'left1',
    [-2]: 'left2',
    [-3]: 'left3',
    1: 'right1',
    2: 'right2',
    3: 'right3',
};

const MOBILE_SLOT = {
    0: 'center',
    [-1]: 'left1',
    1: 'right1',
};

export function wrappedOffset(index, active, count) {
    if (count <= 0) {
        return 0;
    }
    let delta = index - active;
    const half = Math.floor(count / 2);
    if (delta > half) {
        delta -= count;
    }
    if (delta < -half) {
        delta += count;
    }
    return delta;
}

export function variantForOffset(offset, narrow) {
    const slot = (narrow ? MOBILE_SLOT : DESKTOP_SLOT)[offset];
    if (!slot) {
        return null;
    }
    return (narrow ? mobileVariants : imageVariants)[slot];
}
