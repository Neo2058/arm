<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Учёт ЛС | ТЧ-15</title>
    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/app.css', 'resources/js/app.jsx'])
    @endif
</head>
<body class="bg-[#FDFDFC] dark:bg-[#0a0a0a] text-orange-800 dark:text-orange-200 min-h-screen">
<div class="flex h-screen overflow-hidden">
    <aside class="w-64 bg-white dark:bg-[#0b1018] border-r border-gray-200 dark:border-white/10 flex flex-col">
        <div class="p-5 border-b border-gray-100 dark:border-white/10">
            <div class="font-bold text-xl">ТЧ-<span class="text-red-600">15</span></div>
            <div class="text-[10px] text-orange-500">УЧЁТ / ЛИЦЕВЫЕ СЧЕТА</div>
        </div>
        <nav class="p-3 space-y-1 text-sm">
            <a href="{{ route('uchet.index', ['month' => $month ?? date('Y-m')]) }}" class="block px-4 py-2 rounded-2xl hover:bg-orange-500/10">Учётные карточки</a>
            <a href="{{ route('uchet.accounts', ['month' => $month ?? date('Y-m')]) }}" class="block px-4 py-2 rounded-2xl hover:bg-orange-500/10">Лицевые счета</a>
            <a href="{{ route('uchet.extras', ['month' => $month ?? date('Y-m')]) }}" class="block px-4 py-2 rounded-2xl hover:bg-orange-500/10">Доплаты и премия</a>
            <a href="{{ route('uchet.reports', ['month' => $month ?? date('Y-m')]) }}" class="block px-4 py-2 rounded-2xl hover:bg-orange-500/10">Отчёты</a>
            <a href="{{ route('uchet.lsbuh', ['month' => $month ?? date('Y-m')]) }}" class="block px-4 py-2 rounded-2xl hover:bg-orange-500/10">Выгрузка LSBUH</a>
            @if(auth()->user()?->isDispatcher())
                <a href="{{ route('naryad.index') }}" class="block px-4 py-2 rounded-2xl hover:bg-orange-500/10">← Сетка наряда</a>
            @endif
        </nav>
        <div class="mt-auto p-4 text-xs text-orange-400">
            {{ auth()->user()?->name }} · {{ auth()->user()?->roleEnum()->label() }}
            <form method="POST" action="{{ route('logout') }}" class="mt-2">@csrf
                <button class="text-red-500">Выйти</button>
            </form>
        </div>
    </aside>
    <main class="flex-1 overflow-auto p-6">
        @yield('content')
    </main>
</div>
</body>
</html>
