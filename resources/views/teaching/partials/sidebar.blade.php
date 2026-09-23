@php

    /*
    |--------------------------------------------------------------------------
    | SVG Icons
    |--------------------------------------------------------------------------
    | Production-ready:
    | - иконки вынесены отдельно
    | - меню не содержит HTML
    | - легко масштабируется
    | - легко подключать permissions / roles
    */

    $icons = [

        'dashboard' => '
            <svg xmlns="http://www.w3.org/2000/svg"
                 height="24px"
                 viewBox="0 -960 960 960"
                 width="24px"
                 fill="currentColor">
                <path d="M120-520v-320h320v320H120Zm0 400v-320h320v320H120Zm400-400v-320h320v320H520Zm0 400v-320h320v320H520Z"/>
            </svg>
        ',

        'documents' => '
            <svg xmlns="http://www.w3.org/2000/svg"
                 height="24px"
                 viewBox="0 -960 960 960"
                 width="24px"
                 fill="currentColor">
                <path d="M320-240h320v-80H320v80Zm0-160h320v-80H320v80Zm0-160h160v-80H320v80ZM240-80q-33 0-56.5-23.5T160-160v-640q0-33 23.5-56.5T240-880h320l240 240v480q0 33-23.5 56.5T720-80H240Z"/>
            </svg>
        ',

        'quiz' => '
            <svg xmlns="http://www.w3.org/2000/svg"
                 height="24px"
                 viewBox="0 -960 960 960"
                 width="24px"
                 fill="currentColor">
                <path d="M480-80 280-280l56-56 104 104 184-184 56 56L480-80ZM200-200h80v-80h-80v80Zm0-160h80v-80h-80v80Zm0-160h80v-80h-80v80Zm160 0h400v-80H360v80Zm0 160h400v-80H360v80Zm0 160h240v-80H360v80Z"/>
            </svg>
        ',

        'results' => '
            <svg xmlns="http://www.w3.org/2000/svg"
                 height="24px"
                 viewBox="0 -960 960 960"
                 width="24px"
                 fill="currentColor">
                <path d="M280-280h80v-200h-80v200Zm160 0h80v-320h-80v320Zm160 0h80v-440h-80v440ZM200-120q-33 0-56.5-23.5T120-200v-560q0-33 23.5-56.5T200-840h560q33 0 56.5 23.5T840-760v560q0 33-23.5 56.5T760-120H200Z"/>
            </svg>
        ',

        'logout' => '
            <svg xmlns="http://www.w3.org/2000/svg"
                 height="24px"
                 viewBox="0 -960 960 960"
                 width="24px"
                 fill="currentColor">
                <path d="M200-120q-33 0-56.5-23.5T120-200v-560q0-33 23.5-56.5T200-840h280v80H200v560h280v80H200Zm440-160-55-58 102-102H360v-80h327L585-622l55-58 200 200-200 200Z"/>
            </svg>
        ',

    ];


    /*
    |--------------------------------------------------------------------------
    | Documents Menu
    |--------------------------------------------------------------------------
    */

    $documentsMenu = [

        [
            'title' => 'Следующее ТУ',
            'route' => 'timer',
            'icon' => 'dashboard',
        ],

        [
            'title' => 'Документы',
            'route' => 'documents.index',
            'icon' => 'documents',
        ],

        [
            'title' => 'Общий тест',
            'route' => 'quiz.general',
            'icon' => 'quiz',
        ],

        [
            'title' => 'Результаты тестов',
            'route' => 'quiz.results.history',
            'icon' => 'results',
            'count' => $unreadCount ?? 0, // <-- Подставляем динамический счетчик вместо жесткой цифры 5
        ],

        [
            'title' => 'Темы обучения',
            'route' => 'training.topics',
            'icon' => 'quiz',
        ],

        [
            'title' => 'Обратно в меню',
            'route' => 'mainMenu',
            'icon' => 'logout',
        ],

    ];

    $worktimeMenu = [

        [
            'title' => 'Следующее ТУ',
            'route' => 'timer',
            'icon' => 'dashboard',
        ],

        [
            'title' => 'Документы',
            'route' => 'documents.index',
            'icon' => 'documents',
        ],

        [
            'title' => 'Обратно в меню',
            'route' => 'mainMenu',
            'icon' => 'logout',
        ],

    ];

    $backstageMenu = [

        [
            'title' => 'Следующее ТУ',
            'route' => 'timer',
            'icon' => 'dashboard',
        ],

        [
            'title' => 'Документы',
            'route' => 'documents.index',
            'icon' => 'documents',
        ],

        [
            'title' => 'Обратно в меню',
            'route' => 'mainMenu',
            'icon' => 'logout',
        ],

    ];

    // Определяем роль пользователя для скрытия пунктов меню
    $user = auth()->user();
    $isInstructor = $user?->isInstructor() ?? false;
    $canSeeRospisiStats = $user?->canViewRospisiStatistics() ?? false;

    $trainingMenu = [

        [
            'title' => 'Темы обучения',
            'route' => 'training.topics',
            'icon' => 'quiz',
        ],

        [
            'title' => 'Росписи',
            'route' => 'rosisi.index',
            'icon' => 'results',
        ],

    ];

    if ($canSeeRospisiStats) {
        $trainingMenu[] = [
            'title' => 'Статистика росписей',
            'route' => 'rosisi.statistics',
            'icon' => 'results',
        ];
    }

    $trainingMenu = array_merge($trainingMenu, [

        [
            'title' => 'Следующее ТУ',
            'route' => 'timer',
            'icon' => 'dashboard',
        ],

        [
            'title' => 'Документы',
            'route' => 'documents.index',
            'icon' => 'documents',
        ],

        [
            'title' => 'Обратно в меню',
            'route' => 'mainMenu',
            'icon' => 'logout',
        ],

    ]);

    $journalMenu = [

        [
            'title' => 'Журнал',
            'route' => 'journal.index',
            'icon' => 'results',
        ],
        [
            'title' => 'Настройка нормативов',
            'route' => 'journal.settings',
            'icon' => 'results',
        ],
        [
            'title' => 'Нормативы',
            'route' => 'journal.standards',
            'icon' => 'quiz',
        ],
        [
            'title' => 'История нормативов',
            'route' => 'journal.history',
            'icon' => 'results',
        ],
        [
            'title' => 'Отчёт по нормативам',
            'route' => 'journal.report',
            'icon' => 'results',
        ],
        [
            'title' => 'Поиск по нарядам',
            'route' => 'journal.naryad-search',
            'icon' => 'documents',
        ],
        [
            'title' => 'Документы',
            'route' => 'documents.index',
            'active' => 'documents.*',
            'icon' => 'documents',
        ],
        [
            'title' => 'Обратно в меню',
            'route' => 'mainMenu',
            'icon' => 'logout',
        ],
    ];

    $naryadyMenu = [

        [
            'title' => 'Просмотр нарядов',
            'route' => 'naryady.index',
            'icon' => 'documents',
            'tab' => 'naryady',
        ],
        [
            'title' => 'Справочник телефонов',
            'route' => 'naryady.index',
            'icon' => 'dashboard',
            'tab' => 'phones',
        ],
        [
            'title' => 'Расшифровка смен',
            'route' => 'naryady.index',
            'icon' => 'results',
            'tab' => 'explanations',
        ],
        [
            'title' => 'Обратно в меню',
            'route' => 'mainMenu',
            'icon' => 'logout',
        ],

    ];


    /*
    |--------------------------------------------------------------------------
    | Active Menu Resolver
    |--------------------------------------------------------------------------
    */

    $menu = match (true) {

        // Инструктор в разделе документов остаётся в контексте журнала ТЧМ
        request()->routeIs('documents.*') && $isInstructor => $journalMenu,
        request()->routeIs('documents.*') => $documentsMenu,
        request()->routeIs('quiz.*') => $documentsMenu,
        request()->routeIs('results.*') => $documentsMenu,
        request()->routeIs('timer') => $documentsMenu,
        request()->routeIs('work.time.index') => $worktimeMenu,
        request()->routeIs('backstage') => $backstageMenu,
        request()->routeIs('backstage.*') => $backstageMenu,
        request()->routeIs('training.*') => $trainingMenu,
        request()->routeIs('rosisi') => $trainingMenu,
        request()->routeIs('rosisi.*') => $trainingMenu,
        request()->routeIs('podstroiki') => $trainingMenu,
        request()->routeIs('podstroiki.*') => $trainingMenu,
        request()->routeIs('journal') => $journalMenu,
        request()->routeIs('journal.*') => $journalMenu,
        request()->routeIs('naryady.*') => $naryadyMenu,
        request()->routeIs('naryady') => $naryadyMenu,

        default => [],
    };

@endphp


<aside class="aside" id="main-aside">
    <div class="top">
        <div class="logo">
            <img class="logo__img" src="{{ asset('images/logo.jpg') }}" alt="logo">
            <h1 class="logo__header">ТЧ-<span class="danger">15</span></h1>
        </div>
        <!-- Кнопка закрытия для мобилок -->
        <button class="close-btn lg:hidden p-2 text-zinc-400 hover:text-white" id="close-sidebar-btn">
            <svg xmlns="http://www.w3.org/2000/svg" height="24" viewBox="0 -960 960 960" width="24" fill="currentColor"><path d="m256-200-56-56 224-224-224-224 56-56 224 224 224-224 56 56-224 224 224 224-56 56-224-224-224 224Z"/></svg>
        </button>
    </div>
    <div class="sidebar">
        @foreach($menu as $item)
            @php
                $linkHref = route($item['route']);
                if (!empty($item['tab'])) {
                    $linkHref .= '?tab=' . $item['tab'];
                }
                $activeRoute = $item['active'] ?? $item['route'];
                $isActive = request()->routeIs($activeRoute) &&
                    (empty($item['tab']) || request()->query('tab', 'naryady') === $item['tab']);
            @endphp
            <a href="{{ $linkHref }}" class="sidebar__link {{ $isActive ? 'sidebar__link-active' : '' }}">
                <span class="material-icons-sharp">{!! $icons[$item['icon']] ?? '' !!}</span>
                <h3 class="sidebar__link-title">{{ $item['title'] }}</h3>
                @isset($item['count'])
                    @if($item['count'] > 0)
                        <span class="message-count">
                            {{ $item['count'] }}
                        </span>
                    @endif
                @endisset
            </a>
        @endforeach
    </div>
</aside>

<!-- Кнопка ОТКРЫТИЯ мобильного меню (видна только на экранах < 1024px) -->
<button class="mobile-burger-btn lg:hidden fixed top-4 left-4 z-50 p-3 bg-zinc-900 border border-white/10 text-white rounded-xl shadow-2xl" id="open-sidebar-btn">
    <svg xmlns="http://www.w3.org/2000/svg" height="24" viewBox="0 -960 960 960" width="24" fill="currentColor"><path d="M120-240v-80h720v80H120Zm0-200v-80h720v80H120Zm0-200v-80h720v80H120Z"/></svg>
</button>

<script>
    document.addEventListener("DOMContentLoaded", function() {
        const aside = document.getElementById('main-aside');
        const openBtn = document.getElementById('open-sidebar-btn');
        const closeBtn = document.getElementById('close-sidebar-btn');

        if(openBtn && closeBtn && aside) {
            openBtn.onclick = () => aside.classList.add('aside-open');
            closeBtn.onclick = () => aside.classList.remove('aside-open');
        }
    });
</script>
