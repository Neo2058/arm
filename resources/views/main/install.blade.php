<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Приложение на телефон • АРМ ТЧ-15</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />
    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/app.css', 'resources/js/app.jsx'])
    @endif
</head>
<body class="bg-[#FDFDFC] dark:bg-[#0a0a0a] text-[#1b1b18] dark:text-[#EDEDEC] min-h-screen">
    <div class="max-w-3xl mx-auto px-6 py-12">
        <div class="mb-10">
            <a href="/" class="text-sm font-medium text-[#706f6c] dark:text-[#A1A09A] hover:text-[#1b1b18] dark:hover:text-white">← На главную</a>
        </div>

        <div class="text-center mb-10">
            <h1 class="text-3xl font-semibold tracking-tight">ТЧ-15 на телефон</h1>
            <p class="mt-3 text-[#706f6c] dark:text-[#A1A09A] max-w-xl mx-auto">
                Поставьте приложение и входите тем же логином, что на сайте.
                Эту страницу можно сохранить и переслать коллегам.
            </p>
            <p class="mt-4 text-sm">
                <span class="text-[#706f6c] dark:text-[#A1A09A]">Ссылка для скачивания:</span>
                <a href="{{ url('/install') }}" class="ml-1 font-medium text-orange-600 break-all">{{ url('/install') }}</a>
            </p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
            <div class="bg-white dark:bg-[#161615] border {{ $isAndroid ? 'border-orange-500' : 'border-[#e3e3e0] dark:border-[#3E3E3A]' }} rounded-3xl p-6">
                <h2 class="text-xl font-semibold mb-2">Android</h2>
                <p class="text-sm text-[#706f6c] dark:text-[#A1A09A] mb-5">
                    Нажмите кнопку, откройте скачанный файл и подтвердите установку, если телефон спросит разрешение.
                </p>
                @if($androidReady)
                    <a href="{{ route('install.android') }}"
                       class="block w-full h-12 bg-orange-500 hover:bg-orange-600 text-white font-bold rounded-2xl text-center leading-[48px]">
                        Скачать для Android
                    </a>
                @else
                    <p class="text-sm text-[#706f6c] dark:text-[#A1A09A]">Пока откройте сайт в браузере телефона. Файл установки появится здесь, как только будет готов.</p>
                @endif
            </div>

            <div class="bg-white dark:bg-[#161615] border {{ $isIos ? 'border-orange-500' : 'border-[#e3e3e0] dark:border-[#3E3E3A]' }} rounded-3xl p-6">
                <h2 class="text-xl font-semibold mb-2">iPhone</h2>
                <p class="text-sm text-[#706f6c] dark:text-[#A1A09A] mb-5">
                    Нажмите кнопку прямо на iPhone. Если установка недоступна — откройте сайт в Safari.
                </p>
                @if($iosReady)
                    <a href="{{ route('install.ios') }}"
                       class="block w-full h-12 bg-orange-500 hover:bg-orange-600 text-white font-bold rounded-2xl text-center leading-[48px]">
                        Установить на iPhone
                    </a>
                @else
                    <p class="text-sm text-[#706f6c] dark:text-[#A1A09A]">Для iPhone установка пока недоступна. Откройте сайт в Safari — вход тот же.</p>
                @endif
            </div>
        </div>

        <p class="mt-8 text-center text-sm text-[#706f6c] dark:text-[#A1A09A]">
            Уже есть учётная запись?
            <a href="{{ route('login') }}" class="underline text-orange-600">Войти на сайт</a>
        </p>
    </div>
</body>
</html>
