@extends('teaching.layouts.index')

@section('content')
    <!-- Чистый Tailwind: flex-col для мобилок, lg:flex-row для ПК. Фон всегда темный -->
    <div class="flex flex-col lg:flex-row min-h-screen bg-[#0b1018] text-white w-full max-w-full m-0 p-0 overflow-x-hidden">
        <!-- Контентная часть: lg:flex-1 заставляет блок занять ВСЮ оставшуюся ширину экрана на ПК -->
        <main class="flex-1 p-4 md:p-8 min-w-0 w-full bg-[#0b1018]">
            <!-- pt-16 делает отступ для плавающего мобильного бургера, на ПК (lg:pt-0) он исчезает -->
            <div class="w-full max-w-5xl mx-auto pt-16 lg:pt-0">
                <h1 class="text-2xl md:text-3xl font-black mb-1 tracking-tight text-white">История тестирования</h1>
                <p class="text-xs md:text-sm text-zinc-400 mb-6 md:mb-8">Детальный разбор ваших ответов и протоколов успеваемости.</p>

                <div class="space-y-4">
                    @foreach($results as $result)
                        @php
                            $percent = $result->total_questions > 0 ? round(($result->score / $result->total_questions) * 100, 1) : 0;
                            $isPassed = $percent >= 80;
                        @endphp

                            <!-- Карточка теста -->
                        <div class="rounded-2xl border border-white/10 bg-white/5 overflow-hidden transition hover:border-white/20 shadow-2xl">
                            <div class="p-4 md:p-5 flex flex-col sm:flex-row sm:items-center justify-between gap-4 bg-white/[0.01] cursor-pointer select-none" onclick="toggleReport({{ $result->id }})">
                                <div class="min-w-0 flex-1">
                                    <h3 class="font-bold text-sm md:text-base text-zinc-200 truncate pr-4">{{ $result->quiz->title ?? 'Удаленный test' }}</h3>
                                    <span class="text-[10px] md:text-xs text-zinc-500 block mt-1">Дата сдачи: {{ $result->created_at->format('d.m.Y H:i') }}</span>
                                </div>

                                <div class="flex items-center justify-between sm:justify-end gap-4 flex-shrink-0 border-t border-white/5 sm:border-0 pt-3 sm:pt-0">
                                    <div class="text-left sm:text-right">
                                        <span class="text-[10px] md:text-xs text-zinc-400 block">Результат</span>
                                        <span class="font-mono font-bold text-xs md:text-sm text-zinc-300">{{ $result->score }} из {{ $result->total_questions }}</span>
                                    </div>

                                    <div class="px-3 py-1.5 md:px-4 md:py-2 rounded-xl text-center text-xs md:text-sm font-black border {{ $isPassed ? 'bg-emerald-500/10 border-emerald-500/25 text-emerald-400' : 'bg-rose-500/10 border-rose-500/25 text-rose-400' }}">
                                        {{ $percent }}%
                                    </div>

                                    <svg id="icon-{{ $result->id }}" class="w-4 h-4 md:w-5 md:h-5 text-zinc-500 transition-transform duration-300 hidden sm:block" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                                </div>
                            </div>

                            <!-- Скрытый блок детального разбора вопросов -->
                            <div id="report-{{ $result->id }}" class="hidden p-4 md:p-5 border-t border-white/10 bg-black/30 space-y-4">
                                <h4 class="text-[11px] font-bold uppercase tracking-wider text-orange-400 mb-2">Работа над ошибками:</h4>

                                @php
                                    $reportData = [];
                                    if ($result->quiz) {
                                        foreach ($result->quiz->questions as $question) {
                                            $userAnswerId = $result->answers_log[$question->id] ?? null;
                                            $userAnswerText = 'Нет ответа';
                                            $correctAnswerText = 'Не указан';

                                            foreach ($question->answers as $answer) {
                                                if ($answer->is_correct) $correctAnswerText = $answer->answer_text;
                                                if ($answer->id == $userAnswerId) $userAnswerText = $answer->answer_text;
                                            }
                                            $reportData[] = [
                                                'question' => $question->question_text,
                                                'user_answer' => $userAnswerText,
                                                'correct_answer' => $correctAnswerText,
                                                'is_right' => $userAnswerText === $correctAnswerText
                                            ];
                                        }
                                    }
                                @endphp

                                @foreach($reportData as $index => $item)
                                    <div class="p-3 md:p-4 rounded-xl border {{ $item['is_right'] ? 'bg-emerald-500/[0.01] border-emerald-500/15' : 'bg-rose-500/[0.01] border-rose-500/15' }}">
                                        <span class="text-[10px] text-zinc-500 block mb-1">Вопрос {{ $index + 1 }}</span>
                                        <p class="text-xs md:text-sm font-medium text-zinc-300 mb-3 leading-relaxed">{{ $item['question'] }}</p>

                                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-xs">
                                            <div class="p-2.5 md:p-3 rounded-lg bg-black/40">
                                                <span class="text-zinc-500 text-[10px] block mb-1">Ваш ответ на вопрос:</span>
                                                <span class="{{ $item['is_right'] ? 'text-emerald-400 font-bold' : 'text-rose-400 font-bold' }}">
                                                {{ $item['user_answer'] }}
                                            </span>
                                            </div>

                                            @if(!$item['is_right'])
                                                <div class="p-2.5 md:p-3 rounded-lg bg-emerald-500/5 border border-emerald-500/10">
                                                    <span class="text-emerald-500/70 text-[10px] block mb-1">Правильный ответ:</span>
                                                    <span class="text-emerald-400 font-bold">{{ $item['correct_answer'] }}</span>
                                                </div>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach

                    @if($results->isEmpty())
                        <div class="text-center py-12 border border-dashed border-white/10 rounded-2xl text-zinc-500 text-xs md:text-sm">
                            Вы еще не проходили тестирование.
                        </div>
                    @endif
                </div>
            </div>
        </main>
    </div>

    <script>
        function toggleReport(id) {
            const reportBlock = document.getElementById(`report-${id}`);
            const icon = document.getElementById(`icon-${id}`);
            if(reportBlock) {
                if(reportBlock.classList.contains('hidden')) {
                    reportBlock.classList.remove('hidden');
                    if(icon) icon.classList.add('rotate-180');
                } else {
                    reportBlock.classList.add('hidden');
                    if(icon) icon.classList.remove('rotate-180');
                }
            }
        }
    </script>
@endsection
