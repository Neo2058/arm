@extends('teaching.layouts.registry')

@section('content')
    <div class="relative min-h-screen overflow-hidden bg-[#0b1018] text-white flex items-center justify-center p-4">
        <!-- Фоновое свечение в стиле карусели -->
        <div class="absolute inset-0 bg-[radial-gradient(circle_at_center,rgba(249,115,22,0.08),transparent_50%)]"></div>
        <div class="absolute inset-0 bg-[linear-gradient(to_bottom,rgba(255,255,255,0.01),transparent)]"></div>

        <div class="relative z-10 w-full max-w-md">
            <!-- Анимированная иконка-сканер -->
            <div class="mb-8 flex flex-col items-center justify-center">
                <div class="relative h-20 w-20 flex items-center justify-center rounded-2xl border border-orange-500/30 bg-orange-500/10 text-orange-400 shadow-[0_0_30px_rgba(249,115,22,0.15)]">
                    <!-- Пульсирующая линия сканера -->
                    <div class="absolute inset-x-0 h-0.5 bg-orange-400 shadow-[0_0_10px_#f97316] animate-bounce top-0 bottom-0"></div>
                    <svg class="h-10 w-10 animate-pulse" xmlns="http://w3.org" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 17.25v1.007a3 3 0 0 1-.879 2.122L7.5 21h9l-.621-.621A3 3 0 0 1 15 18.257V17.25m6-12V15a2.25 2.25 0 0 1-2.25 2.25H5.25A2.25 2.25 0 0 1 3 15V5.25m18 0A2.25 2.25 0 0 0 18.75 3H5.25A2.25 2.25 0 0 0 3 5.25m18 0V12a2.25 2.25 0 0 1-2.25 2.25H5.25A2.25 2.25 0 0 1 3 12V5.25" />
                    </svg>
                </div>
            </div>

            <!-- Основная карточка формы -->
            <div class="rounded-3xl border border-white/10 bg-white/5 p-8 backdrop-blur-xl shadow-2xl">
                <div class="text-center mb-6">
                    <h1 class="text-2xl font-black tracking-tight mb-2">Идентификация устройства</h1>
                    <p class="text-sm text-zinc-400 leading-relaxed">
                        Этот браузер еще не привязан к вашей учетной записи. Отправьте запрос для авторизации «железа».
                    </p>
                </div>

                <!-- Вывод сообщений об успехе -->
                @if(session('success'))
                    <div class="mb-6 p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 text-sm flex items-start gap-3">
                        <svg class="h-5 w-5 flex-shrink-0 mt-0.5" xmlns="http://w3.org" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm3.859-9.809a.75.75 0 0 0-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 1 0-1.06 1.061l2.5 2.5a.75.75 0 0 0 1.137-.089l4-5.5Z" clip-rule="evenodd" />
                        </svg>
                        <span>{{ session('success') }}</span>
                    </div>
                @else
                    <!-- Форма отправки заявки -->
                    <form action="{{ route('device.register.submit') }}" method="POST" class="space-y-5">
                        @csrf

                        <!-- Скрытое поле для хэша, который генерирует JS -->
                        <input type="hidden" name="device_key" id="device_key_input">

                        <div>
                            <label class="text-xs font-semibold uppercase tracking-wider text-zinc-400 block mb-2">
                                Название оборудования
                            </label>
                            <div class="relative">
                                <input
                                    type="text"
                                    name="device_name"
                                    id="device_name_input"
                                    placeholder="Например: Рабочий Mac, Личный iPhone"
                                    required
                                    class="h-12 w-full rounded-xl border border-white/10 bg-black/25 px-4 text-sm text-white placeholder-zinc-600 outline-none transition focus:border-orange-500/40 focus:ring-1 focus:ring-orange-500/20"
                                >
                            </div>
                        </div>

                        <button
                            type="submit"
                            class="w-full h-12 bg-orange-500 hover:bg-orange-600 text-white font-bold rounded-xl transition-all duration-200 transform hover:scale-[1.02] active:scale-[0.98] shadow-[0_4px_20px_rgba(249,115,22,0.2)] flex items-center justify-center gap-2"
                        >
                            <span>Запросить доступ</span>
                            <svg class="h-4 w-4" xmlns="http://w3.org" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 12 3.269 3.125A59.769 59.769 0 0 1 21.485 12 59.768 59.768 0 0 1 3.27 20.875L5.999 12Zm0 0h7.5" />
                            </svg>
                        </button>
                    </form>
                @endif

                <!-- Ссылка на выход, если пользователь ошибся аккаунтом -->
                <div class="mt-6 text-center border-t border-white/5 pt-4">
                    <form action="{{ route('logout') }}" method="POST">
                        @csrf
                        <button type="submit" class="text-xs text-zinc-500 hover:text-orange-400 transition">
                            Выйти из системы
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <script>
        document.addEventListener("DOMContentLoaded", function() {
        // Функция чтения куки
        function getCookie(name) {
        let matches = document.cookie.match(new RegExp("(?:^|; )" + name.replace(/([\.$?*|{}\(\)\[\]\\\/\+^])/g, '\\$1') + "=([^;]*)"));
        return matches ? decodeURIComponent(matches[1]) : undefined;
        }

        // Быстрая генерация хэша устройства если куки нет (fallback, чтобы register не падал)
        function ensureDeviceKey() {
            if (getCookie('device_key')) return getCookie('device_key');

            try {
                const canvas = document.createElement('canvas');
                const ctx = canvas.getContext('2d');
                ctx.textBaseline = "top";
                ctx.font = "14px 'Arial'";
                ctx.fillText("ARM-Security-Token", 2, 2);
                const canvasData = canvas.toDataURL();
                const raw = canvasData + navigator.userAgent + screen.width + screen.height + (navigator.hardwareConcurrency || 2);

                let hash = 0;
                for (let i = 0; i < raw.length; i++) {
                    const chr = raw.charCodeAt(i);
                    hash = ((hash << 5) - hash) + chr;
                    hash |= 0;
                }
                const hashHex = Math.abs(hash).toString(16).padStart(8, '0') + Math.abs(hash ^ 0x55555555).toString(16).padStart(8, '0');

                document.cookie = `device_key=${hashHex}; path=/; max-age=157680000; SameSite=Lax; Secure`;
                return hashHex;
            } catch (e) {
                console.error('device key gen fallback failed', e);
                return null;
            }
        }

        // Подставляем хэш устройства в скрытый инпут (генерируем при необходимости)
        const deviceKeyInput = document.getElementById('device_key_input');
        const deviceKey = ensureDeviceKey();
        if (deviceKey && deviceKeyInput) {
            deviceKeyInput.value = deviceKey;
        }

        // Интеллектуальное автоопределение имени устройства для удобства
        const inputName = document.getElementById('device_name_input');
        if (inputName && !inputName.value) {
            const isMobile = /iPhone|iPad|iPod|Android/i.test(navigator.userAgent);
            const browser = navigator.userAgent.includes("Chrome") ? "Chrome" : navigator.userAgent.includes("Safari") ? "Safari" : "Браузер";
            inputName.value = isMobile ? `Мобильное устройство (${browser})` : `Стационарный ПК (${browser})`;
        }
        });
    </script>
@endsection
