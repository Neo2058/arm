@extends('teaching.layouts.index')

@section('content')
        <div class="max-w-5xl mx-auto">
            <!-- Header with back button - always visible, mobile friendly -->
            <div class="mb-6">
                <div class="text-center">
                    <h1 class="text-3xl sm:text-4xl font-bold text-orange-400">Связь с разработчиком</h1>
                    <p class="text-zinc-400 text-sm sm:text-base mt-1">Backstage — прямая связь с командой. Сообщите о проблеме, идее или поддержите проект.</p>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-5 gap-6">

                <!-- Форма обратной связи -->
                <div class="lg:col-span-3 {{ ($user->role->value ?? $user->role) === 'driver' ? '' : 'lg:col-span-5' }}">
                    <div class="bg-[#0b1018] border border-white/10 rounded-3xl p-6 shadow-2xl">
                        <div class="flex items-center gap-3 mb-4">
                            <div class="w-9 h-9 rounded-xl bg-orange-500/10 flex items-center justify-center text-orange-400">
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z" />
                                </svg>
                            </div>
                            <h2 class="text-xl font-bold text-white">Написать разработчику</h2>
                        </div>

                        <form id="backstage-form" enctype="multipart/form-data" class="space-y-4">
                            @csrf

                            <div>
                                <label class="text-[10px] uppercase font-bold text-zinc-500 block mb-1">Ваше имя</label>
                                <input type="text" name="name" value="{{ $user->name ?? '' }}"
                                       class="w-full bg-black/30 border border-white/10 rounded-xl px-4 py-2.5 text-sm text-white focus:border-orange-500/40 transition">
                            </div>

                            <div>
                                <label class="text-[10px] uppercase font-bold text-zinc-500 block mb-1">Сообщение или предложение</label>
                                <textarea name="message" required rows="5" placeholder="Опишите проблему, идею или пожелание..."
                                          class="w-full bg-black/30 border border-white/10 rounded-2xl p-4 text-sm text-white outline-none focus:border-orange-500/40 transition resize-y min-h-[120px]"></textarea>
                            </div>

                            <div>
                                <label class="text-[10px] uppercase font-bold text-zinc-500 block mb-1">Прикрепить фото или скриншот (необязательно)</label>
                                <input type="file" name="attachment" accept="image/*"
                                       class="w-full text-sm text-zinc-400 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-semibold file:bg-white/5 file:text-orange-400 hover:file:bg-white/10 file:cursor-pointer">
                                <p class="text-[10px] text-zinc-500 mt-1">Макс. 5 МБ. Будет загружено в защищённое хранилище.</p>
                            </div>

                            <button type="submit" id="backstage-submit-btn"
                                    class="w-full h-12 mt-2 bg-orange-500 hover:bg-orange-600 active:bg-orange-700 text-white font-bold rounded-2xl transition flex items-center justify-center gap-2 shadow-lg shadow-orange-500/20">
                                <span>Отправить сообщение</span>
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Поддержать проект -->
                @if( ($user->role->value ?? $user->role) === 'driver' )
                <div class="lg:col-span-2">
                    <div class="bg-[#0b1018] border border-white/10 rounded-3xl p-6 shadow-2xl">
                        <div class="flex items-center gap-3 mb-4">
                            <div class="w-9 h-9 rounded-xl bg-emerald-500/10 flex items-center justify-center text-emerald-400">
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z" />
                                </svg>
                            </div>
                            <h2 class="text-xl font-bold text-white">Поддержать проект</h2>
                        </div>

                        <p class="text-sm text-zinc-400 mb-4 leading-relaxed">
                            Если проект помогает в работе или вы хотите поблагодарить — поддержите дальнейшую разработку.
                        </p>

                        <!-- Donation buttons: always 2 columns for narrow support column on large screens -->
                        <div class="grid grid-cols-2 gap-2 mb-4">
                            @foreach([100, 300, 500, 1000] as $amount)
                                <button type="button"
                                        class="donate-btn group px-4 py-3 bg-white/5 hover:bg-emerald-500/25 border border-white/10 hover:border-emerald-500/30 rounded-2xl text-emerald-300 hover:text-emerald-200 text-sm font-semibold transition-all duration-300 ease-out active:scale-[0.985] min-h-[48px]"
                                        data-amount="{{ $amount }}">
                                    {{ $amount }} ₽
                                </button>
                            @endforeach
                        </div>

                        <!-- Custom amount: vertical stack to guarantee it fits inside the card on all widths (incl. >1024px) -->
                        <div class="space-y-2">
                            <label class="text-[10px] uppercase font-bold text-zinc-500 block">Своя сумма</label>
                            <input id="custom-amount" type="number" placeholder="Например 750"
                                   class="w-full bg-black/30 border border-white/10 rounded-xl px-4 py-3 text-sm text-white focus:border-emerald-500/40 min-h-[48px]">
                            <button id="donate-custom-btn"
                                    class="w-full px-6 py-3 bg-emerald-500 hover:bg-emerald-600 text-white font-bold rounded-xl text-sm transition min-h-[48px] active:scale-[0.985]">
                                Пожертвовать
                            </button>
                            <p class="text-[10px] text-center text-zinc-500">Спасибо! Ваш вклад очень важен для проекта.</p>
                        </div>

                        <div class="mt-4 pt-4 border-t border-white/10 text-[10px] text-zinc-500">
                            Оплата через ЮKassa. Деньги идут напрямую на развитие.
                        </div>
                    </div>
                </div>
                @endif

            </div>

            <!-- Дополнительная информация -->
            <div class="mt-8 text-center text-xs text-zinc-500">
                Все сообщения и донаты приходят напрямую разработчику. Данные хранятся конфиденциально.
            </div>
        </div>


<script>
document.addEventListener("DOMContentLoaded", function() {
    // === FEEDBACK FORM ===
    const feedbackForm = document.getElementById('backstage-form');
    const feedbackBtn = document.getElementById('backstage-submit-btn');

    if (feedbackForm) {
        feedbackForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            feedbackBtn.disabled = true;
            feedbackBtn.innerHTML = '<span>Отправка...</span>';

            try {
                const formData = new FormData(feedbackForm);
                const response = await fetch('{{ route("backstage.store") }}', {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                    body: formData
                });

                const data = await response.json();
                alert(data.message || 'Спасибо! Сообщение отправлено.');
                feedbackForm.reset();
            } catch (err) {
                alert('Произошла ошибка при отправке. Попробуйте ещё раз.');
            } finally {
                feedbackBtn.disabled = false;
                feedbackBtn.innerHTML = '<span>Отправить сообщение</span>';
            }
        });
    }

    // === DONATION BUTTONS (Yookassa flow) ===
    const donateButtons = document.querySelectorAll('.donate-btn');
    const customAmountInput = document.getElementById('custom-amount');
    const customDonateBtn = document.getElementById('donate-custom-btn');

    async function submitSupport(amount) {
        if (!amount || amount < 10) {
            alert('Минимальная сумма поддержки — 10 ₽');
            return;
        }

        const btns = document.querySelectorAll('.donate-btn, #donate-custom-btn');
        btns.forEach(b => b.disabled = true);

        try {
            const response = await fetch('{{ route("backstage.support") }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                },
                body: JSON.stringify({ amount: amount })
            });

            const data = await response.json();

            if (data.confirmation_url) {
                // Redirect to Yookassa payment page
                window.location.href = data.confirmation_url;
            } else {
                alert(data.message || 'Спасибо за поддержку!');
            }
        } catch (err) {
            alert('Не удалось обработать поддержку. Пожалуйста, попробуйте позже.');
        } finally {
            btns.forEach(b => b.disabled = false);
            if (customAmountInput) customAmountInput.value = '';
        }
    }

    donateButtons.forEach(btn => {
        btn.addEventListener('click', () => {
            const amount = parseFloat(btn.dataset.amount);
            submitSupport(amount);
        });
    });

    if (customDonateBtn && customAmountInput) {
        customDonateBtn.addEventListener('click', () => {
            const amount = parseFloat(customAmountInput.value);
            submitSupport(amount);
        });

        customAmountInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                const amount = parseFloat(customAmountInput.value);
                submitSupport(amount);
            }
        });
    }
});
</script>
@endsection
