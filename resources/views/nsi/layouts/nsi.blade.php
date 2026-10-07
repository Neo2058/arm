<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>НСИ | ТЧ-15</title>
    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/app.css', 'resources/js/app.jsx'])
    @endif
</head>
<body class="bg-[#FDFDFC] dark:bg-[#0a0a0a] text-orange-800 dark:text-orange-200 min-h-screen">
@php
    $section = $section ?? '';
@endphp
<div class="flex h-screen overflow-hidden">
    <aside class="w-64 bg-white dark:bg-[#0b1018] border-r border-gray-200 dark:border-white/10 flex flex-col">
        <div class="p-5 border-b border-gray-100 dark:border-white/10">
            <div class="font-bold text-xl">ТЧ-<span class="text-red-600">15</span></div>
            <div class="text-[10px] text-orange-500">НСИ / NSIMENU</div>
        </div>
        <nav class="p-3 space-y-1 text-sm">
            <a href="{{ route('nsi.index') }}" class="block px-4 py-2 rounded-2xl {{ $section === '' ? 'bg-orange-500/10' : 'hover:bg-orange-500/10' }}">Меню НСИ</a>
            <a href="{{ route('nsi.codes') }}" class="block px-4 py-2 rounded-2xl {{ $section === 'codes' ? 'bg-orange-500/10' : 'hover:bg-orange-500/10' }}">Кодификаторы</a>
            <a href="{{ route('nsi.catalogs') }}" class="block px-4 py-2 rounded-2xl {{ $section === 'catalogs' ? 'bg-orange-500/10' : 'hover:bg-orange-500/10' }}">Справочники</a>
            <a href="{{ route('nsi.norms') }}" class="block px-4 py-2 rounded-2xl {{ $section === 'norms' ? 'bg-orange-500/10' : 'hover:bg-orange-500/10' }}">Нормативы</a>
            <a href="{{ route('nsi.arm') }}" class="block px-4 py-2 rounded-2xl {{ $section === 'arm' ? 'bg-orange-500/10' : 'hover:bg-orange-500/10' }}">Настройка АРМ</a>
            <a href="{{ route('naryad.index') }}" class="block px-4 py-2 rounded-2xl hover:bg-orange-500/10">← Сетка наряда</a>
            <a href="{{ route('uchet.index') }}" class="block px-4 py-2 rounded-2xl hover:bg-orange-500/10">Учёт / ЛС</a>
        </nav>
        <div class="mt-auto p-4 text-xs text-orange-400">
            {{ auth()->user()?->name }} · {{ auth()->user()?->roleEnum()->label() }}
            <form method="POST" action="{{ route('logout') }}" class="mt-2">@csrf
                <button class="text-red-500">Выйти</button>
            </form>
        </div>
    </aside>
    <main class="flex-1 overflow-auto p-6">
        @if(session('ok'))
            <div class="mb-4 px-4 py-2 rounded-2xl bg-emerald-50 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-300 text-sm">{{ session('ok') }}</div>
        @endif
        @if($errors->any())
            <div class="mb-4 px-4 py-2 rounded-2xl bg-red-50 dark:bg-red-900/30 text-red-700 text-sm">{{ $errors->first() }}</div>
        @endif
        @yield('content')
    </main>
</div>
</body>
</html>
