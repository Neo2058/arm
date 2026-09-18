<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>О сервисе • АРМ ТЧ-15</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />
    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/app.css', 'resources/js/app.jsx'])
    @else
        <style>
            body { font-family: 'Instrument Sans', system-ui, sans-serif; }
        </style>
    @endif
    <style>
        .form-input {
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .form-input:focus {
            outline: none;
            border-color: rgb(249 115 22);
            box-shadow: 0 0 0 3px rgba(249, 115, 22, 0.1);
        }
        .section-title {
            position: relative;
            display: inline-block;
        }
        .section-title:after {
            content: '';
            position: absolute;
            bottom: -4px;
            left: 0;
            width: 40%;
            height: 2px;
            background: linear-gradient(to right, #f97316, transparent);
        }
    </style>
</head>
<body class="bg-[#FDFDFC] dark:bg-[#0a0a0a] text-[#1b1b18] dark:text-[#EDEDEC] min-h-screen">
    <div class="max-w-3xl mx-auto px-6 py-12">
        <!-- Header -->
        <div class="flex items-center justify-between mb-10">
            <div>
                <a href="/" class="flex items-center gap-3 text-sm font-medium text-[#706f6c] dark:text-[#A1A09A] hover:text-[#1b1b18] dark:hover:text-white">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                    </svg>
                    На главную
                </a>
            </div>
            <div class="text-xs px-3 py-1 rounded-full border border-orange-500/30 text-orange-600 dark:text-orange-400 bg-orange-500/5">
                БЕТА • ТЧ-15
            </div>
        </div>

        <!-- Короткий заголовок для страницы формы -->
        <div class="mb-10 text-center">
            <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-orange-500/10 text-orange-600 dark:text-orange-400 text-xs font-medium mb-4">
                БЕТА • ТЧ-15
            </div>
            <h1 class="text-3xl font-semibold tracking-tight">Запрос доступа</h1>
            <p class="mt-2 text-[#706f6c] dark:text-[#A1A09A]">Заполните данные для создания учётной записи</p>
        </div>

        <!-- Форма заявки -->
        <div class="bg-white dark:bg-[#161615] border border-[#e3e3e0] dark:border-[#3E3E3A] rounded-3xl p-8 shadow-sm">
            <div class="mb-6">
                <h2 class="text-2xl font-semibold tracking-tight mb-2">Запрос на создание учётной записи</h2>
                <p class="text-sm text-[#706f6c] dark:text-[#A1A09A]">
                    Заполните форму. Заявка будет немедленно отправлена инструктору или администратору.
                    После рассмотрения вам предоставят доступ.
                </p>
            </div>

            @if(session('success'))
                <div class="mb-6 p-4 bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-800 text-emerald-700 dark:text-emerald-400 rounded-2xl text-sm">
                    {{ session('success') }}
                </div>
            @endif

            @if($errors->any())
                <div class="mb-6 p-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-400 rounded-2xl text-sm">
                    @foreach($errors->all() as $error)
                        <div>• {{ $error }}</div>
                    @endforeach
                </div>
            @endif

            <form action="{{ route('about.request') }}" method="POST" class="space-y-6">
                @csrf

                <!-- Honeypot (скрытое поле для защиты от ботов) -->
                <input type="text" name="website" class="hidden" autocomplete="off" tabindex="-1">

                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                    <div>
                        <label class="block text-xs font-semibold tracking-[0.5px] uppercase text-[#706f6c] dark:text-[#A1A09A] mb-2">
                            Табельный номер
                        </label>
                        <input
                            type="text"
                            name="tab_number"
                            value="{{ old('tab_number') }}"
                            placeholder="Например: 45231"
                            required
                            maxlength="20"
                            autocomplete="off"
                            inputmode="text"
                            pattern="[0-9A-Za-z\-]{4,20}"
                            title="Только цифры, латинские буквы и дефис, 4–20 символов"
                            aria-invalid="{{ $errors->has('tab_number') ? 'true' : 'false' }}"
                            class="form-input w-full h-12 px-4 rounded-2xl border {{ $errors->has('tab_number') ? 'border-red-400 dark:border-red-500' : 'border-[#e3e3e0] dark:border-[#3E3E3A]' }} bg-white dark:bg-[#0a0a0a] text-[#1b1b18] dark:text-white placeholder:text-[#a3a29e] focus:border-orange-500 dark:focus:border-orange-400"
                        >
                        @error('tab_number')
                            <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                        @else
                            <p class="mt-1 text-[10px] text-[#a3a29e]">Только цифры, латинские буквы и дефис. 4–20 символов.</p>
                        @enderror
                    </div>

                    <div>
                        <label class="block text-xs font-semibold tracking-[0.5px] uppercase text-[#706f6c] dark:text-[#A1A09A] mb-2">
                            ФИО (полностью)
                        </label>
                        <input
                            type="text"
                            name="fio"
                            value="{{ old('fio') }}"
                            placeholder="Иванов Иван Иванович"
                            required
                            maxlength="150"
                            autocomplete="name"
                            aria-invalid="{{ $errors->has('fio') ? 'true' : 'false' }}"
                            class="form-input w-full h-12 px-4 rounded-2xl border {{ $errors->has('fio') ? 'border-red-400 dark:border-red-500' : 'border-[#e3e3e0] dark:border-[#3E3E3A]' }} bg-white dark:bg-[#0a0a0a] text-[#1b1b18] dark:text-white placeholder:text-[#a3a29e] focus:border-orange-500 dark:focus:border-orange-400"
                        >
                        @error('fio')
                            <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                        @else
                            <p class="mt-1 text-[10px] text-[#a3a29e]">Фамилия и имя полностью. Буквы, пробелы, дефисы и точки.</p>
                        @enderror
                    </div>
                </div>

                <!-- Чекбокс согласия на обработку ПД -->
                <div class="flex items-start gap-3">
                    <input
                        type="checkbox"
                        id="consent"
                        name="consent"
                        value="1"
                        required
                        @checked(old('consent'))
                        class="mt-1 h-4 w-4 rounded border-[#e3e3e0] dark:border-[#3E3E3A] text-orange-500 focus:ring-orange-500"
                    >
                    <label for="consent" class="text-xs leading-snug text-[#706f6c] dark:text-[#A1A09A]">
                        Соглашаюсь на обработку персональных данных в соответствии с законодательством РФ
                    </label>
                </div>
                @error('consent')
                    <p class="-mt-4 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                @enderror

                <div class="pt-2">
                    <button 
                        type="submit"
                        class="w-full h-12 bg-orange-500 hover:bg-orange-600 active:bg-orange-700 text-white font-bold tracking-wider text-sm rounded-2xl transition-all duration-200 flex items-center justify-center gap-2 shadow-lg hover:shadow-xl transform hover:scale-[1.02] active:scale-[0.985]"
                    >
                        ОТПРАВИТЬ ЗАЯВКУ НА РЕГИСТРАЦИЮ
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M14 5l7 7-7 7M5 12h14" />
                        </svg>
                    </button>
                </div>

                <div class="text-center">
                    <p class="text-[10px] text-[#a3a29e]">
                        Ваши данные защищены. Заявка будет рассмотрена вручную.<br>
                        Попытки несанкционированного доступа логируются и передаются в службу безопасности.
                    </p>
                </div>
            </form>
        </div>

        <div class="mt-8 text-center text-xs text-[#a3a29e]">
            Для уже зарегистрированных сотрудников — <a href="{{ route('login') }}" class="underline hover:text-[#1b1b18] dark:hover:text-white">войти в систему</a>
        </div>
    </div>
</body>
</html>