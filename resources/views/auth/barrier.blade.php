@extends('auth.register-device')

@section('content')
    <div class="relative min-h-screen overflow-hidden bg-[#0b1018] text-white flex items-center justify-center p-4">
        <div class="absolute inset-0 bg-[radial-gradient(circle_at_center,rgba(239,68,68,0.05),transparent_50%)]"></div>

        <div class="relative z-10 w-full max-w-md bg-white/5 border border-white/10 p-8 rounded-3xl backdrop-blur-xl shadow-2xl">
            <div class="text-center mb-6">
                <div class="h-12 w-12 rounded-xl bg-red-500/10 border border-red-500/30 text-red-400 flex items-center justify-center mx-auto mb-4 animate-pulse">
                    <svg class="h-6 w-6" xmlns="http://w3.org" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" />
                    </svg>
                </div>
                <h1 class="text-xl font-bold tracking-tight mb-1">Динамический барьер</h1>
                <p class="text-xs text-zinc-400">Введите протокол верификации смены</p>
            </div>

            <!-- Контейнер для React-компонента барьера, если захотите сделать интерактивно, либо простая HTML форма -->
            <div id="barrier-inputs" class="space-y-4">
                @for ($i = 0; $i < 4; $i++)
                    <div>
                        <label class="text-[10px] uppercase font-bold text-zinc-500 block mb-1">Уровень верификации {{ $i + 1 }}</label>
                        <select data-index="{{ $i }}" class="barrier-select w-full h-11 bg-black/30 border border-white/10 rounded-xl px-3 text-sm outline-none transition focus:border-red-500/40">
                            <option value="0">Протокол Альфа (0)</option>
                            <option value="1">Протокол Бета (1)</option>
                            <option value="2">Протокол Гамма (2)</option>
                            <option value="3">Протокол Дельта (3)</option>
                        </select>
                    </div>
                @endfor

                <button id="submit-barrier" class="w-full h-11 mt-4 bg-red-500 hover:bg-red-600 text-white font-bold text-sm rounded-xl transition shadow-[0_4px_20px_rgba(239,68,68,0.15)]">
                    Подтвердить протокол
                </button>
                <p id="barrier-error" class="text-xs text-red-400 text-center hidden mt-2"></p>
            </div>
        </div>
    </div>

    <script>
        document.getElementById('submit-barrier').addEventListener('click', async function() {
            const selects = document.querySelectorAll('.barrier-select');
            const answers = Array.from(selects).map(s => parseInt(s.value));
            const errorEl = document.getElementById('barrier-error');

            try {
                const response = await fetch('{{ route("barrier.verify") }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    },
                    body: JSON.stringify({ answers: answers })
                });

                const data = await response.json();
                if (data.status === 'success') {
                    window.location.href = '/mainMenu';
                } else {
                    errorEl.textContent = data.message;
                    errorEl.classList.remove('hidden');
                }
            } catch (e) {
                errorEl.textContent = 'Ошибка авторизации барьера.';
                errorEl.classList.remove('hidden');
            }
        });
    </script>
@endsection
