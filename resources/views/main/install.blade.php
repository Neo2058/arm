<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Установить приложение • АРМ ТЧ-15</title>
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
            <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-orange-500/10 text-orange-600 dark:text-orange-400 text-xs font-medium mb-4">
                Нативное приложение
            </div>
            <h1 class="text-3xl font-semibold tracking-tight">ТЧ-15 на телефон</h1>
            <p class="mt-2 text-[#706f6c] dark:text-[#A1A09A]">
                Ставится как обычное приложение, не через браузер. Адрес сервера уже прописан.
            </p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
            <div class="bg-white dark:bg-[#161615] border {{ $isAndroid ? 'border-orange-500' : 'border-[#e3e3e0] dark:border-[#3E3E3A]' }} rounded-3xl p-6">
                <h2 class="text-xl font-semibold mb-2">Android</h2>
                <p class="text-sm text-[#706f6c] dark:text-[#A1A09A] mb-5">
                    Скачайте APK и откройте файл. Если система спросит — разрешите установку из этого источника.
                </p>
                @if($androidReady)
                    <a href="{{ route('install.android') }}"
                       class="block w-full h-12 bg-orange-500 hover:bg-orange-600 text-white font-bold rounded-2xl text-center leading-[48px]">
                        Скачать APK
                    </a>
                @else
                    <p class="text-sm text-amber-700 dark:text-amber-400">Сборка ещё не выложена. После <code class="text-xs">eas build -p android --profile preview</code> скопируйте APK в <code class="text-xs">public/downloads/tch15-android.apk</code>.</p>
                @endif
            </div>

            <div class="bg-white dark:bg-[#161615] border {{ $isIos ? 'border-orange-500' : 'border-[#e3e3e0] dark:border-[#3E3E3A]' }} rounded-3xl p-6">
                <h2 class="text-xl font-semibold mb-2">iPhone / iPad</h2>
                <p class="text-sm text-[#706f6c] dark:text-[#A1A09A] mb-5">
                    Нажмите кнопку на самом iPhone. Установка идёт через профиль (не Safari как сайт). Нужна ad-hoc или enterprise подпись Apple.
                </p>
                @if($iosReady)
                    <a href="{{ route('install.ios') }}"
                       class="block w-full h-12 bg-orange-500 hover:bg-orange-600 text-white font-bold rounded-2xl text-center leading-[48px]">
                        Установить на iOS
                    </a>
                @else
                    <p class="text-sm text-amber-700 dark:text-amber-400">Сборка ещё не выложена. После <code class="text-xs">eas build -p ios --profile preview</code> скопируйте IPA в <code class="text-xs">public/downloads/tch15-ios.ipa</code>.</p>
                @endif
            </div>
        </div>

        <p class="mt-8 text-center text-xs text-[#a3a29e]">
            Это не копия сайта. Документы, видео и комментарии идут в API <code>/api/mobile</code>.
            Уже есть учётная запись? <a href="{{ route('login') }}" class="underline">Войти в веб</a>
        </p>
    </div>
</body>
</html>
