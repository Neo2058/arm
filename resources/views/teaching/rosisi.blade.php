@extends('teaching.layouts.index')

@section('content')
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between mb-8">
        <div>
            <h1 class="text-3xl sm:text-4xl font-bold text-gray-900 dark:text-white tracking-tight">Росписи и записи в формуляр</h1>
            <p class="mt-2 text-gray-600 dark:text-gray-400 max-w-2xl">Ознакомьтесь с инструктажами, пройдите тест и поставьте роспись. Отдельный раздел для записей в формуляр.</p>
        </div>
        <div class="mt-4 sm:mt-0 flex gap-3">
            @if(auth()->user() && in_array(strtolower(auth()->user()->role->value ?? auth()->user()->role), ['super_admin', 'admin', 'instructor']))
                <a href="{{ route('rosisi.statistics') }}" 
                   class="inline-flex items-center px-4 py-2 bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-2xl text-sm font-medium hover:bg-gray-50 dark:hover:bg-gray-700 transition shadow-sm">
                    📊 Статистика
                </a>
            @endif
            <a href="{{ route('mainMenu') }}" 
               class="inline-flex items-center px-4 py-2 bg-orange-500 hover:bg-orange-600 text-white rounded-2xl text-sm font-medium transition shadow-sm">
                ← В меню
            </a>
        </div>
    </div>

    @if(session('success'))
        <div class="mb-6 p-4 bg-green-50 dark:bg-green-900/30 border border-green-200 dark:border-green-700 text-green-700 dark:text-green-300 rounded-2xl flex items-center gap-2 text-sm">
            <span>✅</span>
            <span>{{ session('success') }}</span>
        </div>
    @endif

    <!-- User summary -->
    @php
        $totalDocs = collect($categoriesData)->flatMap(fn($c) => $c['documents'])->count();
        $signedDocs = collect($categoriesData)->flatMap(fn($c) => $c['documents'])->where('signed', true)->count();
        $totalForm = count($formularTasks);
        $doneForm = collect($formularTasks)->where('completed', true)->count();
    @endphp
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-8">
        <div class="bg-white dark:bg-[#0b1018] border border-gray-200 dark:border-white/10 rounded-2xl p-4 flex items-center gap-4">
            <div class="text-3xl">📋</div>
            <div>
                <div class="text-sm text-gray-500 dark:text-gray-400">Росписи инструктажей</div>
                <div class="text-2xl font-semibold text-gray-900 dark:text-white">{{ $signedDocs }} / {{ $totalDocs }}</div>
            </div>
        </div>
        <div class="bg-white dark:bg-[#0b1018] border border-gray-200 dark:border-white/10 rounded-2xl p-4 flex items-center gap-4">
            <div class="text-3xl">📝</div>
            <div>
                <div class="text-sm text-gray-500 dark:text-gray-400">Записи в формуляр</div>
                <div class="text-2xl font-semibold text-gray-900 dark:text-white">{{ $doneForm }} / {{ $totalForm }}</div>
            </div>
        </div>
    </div>

    <!-- Инструктажи и росписи -->
    <div class="mb-12">
        <div class="flex items-center gap-3 mb-4">
            <div class="w-8 h-8 bg-orange-100 dark:bg-orange-900/40 text-orange-600 dark:text-orange-400 rounded-xl flex items-center justify-center text-lg">📋</div>
            <h2 class="text-2xl font-semibold text-gray-900 dark:text-white">Инструктажи и росписи</h2>
        </div>

        @forelse($categoriesData as $cat)
            <div class="mb-8">
                <div class="flex items-center justify-between mb-3 px-1">
                    <h3 class="font-semibold text-xl text-gray-900 dark:text-white">{{ $cat['name'] }}</h3>
                    <span class="text-sm text-gray-500 dark:text-gray-400">{{ collect($cat['documents'])->where('signed', false)->count() }} требуют внимания</span>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    @forelse($cat['documents'] as $doc)
                        <div class="group bg-white dark:bg-[#0b1018] border border-gray-200 dark:border-white/10 rounded-2xl p-5 flex flex-col hover:shadow-md transition-all duration-200">
                            <div class="flex-1">
                                <div class="font-semibold text-gray-900 dark:text-white mb-1 group-hover:text-orange-600 transition">{{ $doc['title'] }}</div>
                                <div class="text-xs text-gray-500 dark:text-gray-400 mb-3">Папка: {{ $cat['name'] }}</div>

                                @if($doc['has_quiz'])
                                    <div class="inline-flex items-center text-xs px-2 py-0.5 rounded-full bg-blue-100 dark:bg-blue-900/40 text-blue-700 dark:text-blue-300 mb-3">
                                        С тестом
                                    </div>
                                @endif
                            </div>

                            <div class="mt-auto pt-4 border-t border-gray-100 dark:border-white/10 flex items-center justify-between">
                                <div>
                                    @if($doc['signed'])
                                        <span class="inline-flex items-center gap-1 text-sm text-green-600 dark:text-green-400">
                                            <span>✓</span> Прочитано
                                        </span>
                                    @else
                                        <span class="inline-flex items-center text-sm text-amber-600 dark:text-amber-400">Требуется роспись</span>
                                    @endif
                                </div>

                                <button 
                                    onclick="openRospisModal({{ $doc['id'] }}, '{{ addslashes($doc['title']) }}', {{ $doc['has_quiz'] ? 'true' : 'false' }}, {{ $doc['quiz_id'] ?? 'null' }}, '{{ $doc['url'] ?? '' }}', {{ $doc['signed'] ? 'true' : 'false' }})"
                                    class="px-4 py-1.5 text-sm font-medium rounded-2xl transition active:scale-95 
                                           {{ $doc['signed'] ? 'bg-gray-100 dark:bg-white/10 text-gray-600 dark:text-gray-300' : 'bg-green-600 hover:bg-green-700 text-white' }}">
                                    {{ $doc['signed'] ? 'Посмотреть' : 'Ознакомиться и расписаться' }}
                                </button>
                            </div>
                        </div>
                    @empty
                        <div class="col-span-full text-center py-6 text-gray-500 dark:text-gray-400 bg-white dark:bg-[#0b1018] border border-gray-200 dark:border-white/10 rounded-2xl">Нет документов</div>
                    @endforelse
                </div>
            </div>
        @empty
            <div class="bg-white dark:bg-[#0b1018] border border-gray-200 dark:border-white/10 rounded-3xl p-8 text-center text-gray-500">Нет активных категорий инструктажей.</div>
        @endforelse
    </div>

    <!-- Записи в формуляр -->
    <div>
        <div class="flex items-center gap-3 mb-4">
            <div class="w-8 h-8 bg-blue-100 dark:bg-blue-900/40 text-blue-600 dark:text-blue-400 rounded-xl flex items-center justify-center text-lg">📝</div>
            <h2 class="text-2xl font-semibold text-gray-900 dark:text-white">Записи в формуляр</h2>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            @forelse($formularTasks as $task)
                <div class="bg-white dark:bg-[#0b1018] border border-gray-200 dark:border-white/10 rounded-2xl p-5 flex flex-col">
                    <div class="flex-1">
                        <div class="font-semibold text-gray-900 dark:text-white">{{ $task['title'] }}</div>
                        @if($task['description'])
                            <div class="text-sm text-gray-600 dark:text-gray-400 mt-1.5">{{ $task['description'] }}</div>
                        @endif
                    </div>

                    <div class="mt-4 pt-4 border-t border-gray-100 dark:border-white/10">
                        @if($task['completed'])
                            <div class="flex items-center gap-2 text-sm text-green-600 dark:text-green-400">
                                <span>✓ Выполнено {{ \Carbon\Carbon::parse($task['completed_at'])->format('d.m.Y') }}</span>
                            </div>
                            @if($task['entry_text'])
                                <div class="mt-2 text-xs bg-gray-50 dark:bg-white/5 p-3 rounded-xl text-gray-600 dark:text-gray-300">
                                    {{ $task['entry_text'] }}
                                </div>
                            @endif
                        @else
                            <form method="POST" action="{{ route('rosisi.log-formular', $task['id']) }}">
                                @csrf
                                <div class="space-y-2">
                                    <input type="text" name="entry_text" placeholder="Что вы записали в формуляр?" 
                                           class="w-full px-3 py-2 text-sm border border-gray-300 dark:border-white/20 rounded-2xl bg-white dark:bg-black/20 focus:border-orange-400" required>
                                    <button type="submit" 
                                            class="w-full px-4 py-2 text-sm bg-blue-600 hover:bg-blue-700 text-white rounded-2xl transition active:scale-[0.985]">
                                        Отметить выполнение
                                    </button>
                                </div>
                            </form>
                        @endif
                    </div>
                </div>
            @empty
                <div class="col-span-full text-center py-6 text-gray-500">Нет активных задач для формуляра.</div>
            @endforelse
        </div>
    </div>
</div>

<!-- Modal for Rospis / Document + Test -->
<div id="rospis-modal" class="fixed inset-0 z-[100] hidden items-center justify-center bg-black/60 p-4" onclick="closeRospisModal()">
    <div onclick="event.stopImmediatePropagation()" class="w-full max-w-3xl bg-white dark:bg-[#0b1018] border border-gray-200 dark:border-white/10 rounded-3xl shadow-2xl overflow-hidden">
        <!-- Modal Header -->
        <div class="px-6 py-4 border-b border-gray-100 dark:border-white/10 flex items-center justify-between bg-gray-50 dark:bg-white/5">
            <h3 id="modal-title" class="font-semibold text-xl text-gray-900 dark:text-white"></h3>
            <button onclick="closeRospisModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 text-2xl leading-none">&times;</button>
        </div>

        <div class="p-6 space-y-6">
            <!-- Document Section -->
            <div>
                <div class="flex items-center gap-2 text-sm font-medium text-gray-500 dark:text-gray-400 mb-2">
                    <span>📄</span> <span>Документ для ознакомления</span>
                </div>
                <a id="modal-doc-link" href="#" target="_blank" 
                   class="inline-flex items-center gap-2 px-5 py-2.5 bg-orange-500 hover:bg-orange-600 text-white text-sm font-medium rounded-2xl transition">
                    Открыть документ для чтения
                </a>
                <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">Откроется в новой вкладке с временной защищённой ссылкой.</p>
            </div>

            <!-- Test Section -->
            <div id="modal-test-section">
                <div class="flex items-center gap-2 text-sm font-medium text-gray-500 dark:text-gray-400 mb-2">
                    <span>📝</span> <span>Тест по документу</span>
                </div>
                <a id="modal-quiz-link" href="#" target="_blank" 
                   class="inline-flex items-center gap-2 px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-2xl transition">
                    Пройти тест прямо сейчас
                </a>
                <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">Рекомендуется пройти тест перед росписью.</p>
            </div>

            <!-- Signature Section -->
            <div class="pt-4 border-t border-gray-100 dark:border-white/10">
                <div class="flex items-center gap-2 text-sm font-medium text-gray-500 dark:text-gray-400 mb-3">
                    <span>✍️</span> <span>Роспись</span>
                </div>
                <form id="modal-sign-form" method="POST" class="space-y-3">
                    @csrf
                    <input type="hidden" id="modal-doc-id" name="document_id">
                    <div class="flex items-start gap-3">
                        <input type="checkbox" id="modal-confirm" required class="mt-1 h-4 w-4 accent-green-600">
                        <label for="modal-confirm" class="text-sm text-gray-700 dark:text-gray-300 leading-tight">
                            Я ознакомился с документом{{ ' и прошёл тест' }} и подтверждаю роспись.
                        </label>
                    </div>
                    <button type="submit" 
                            class="w-full px-6 py-3 bg-green-600 hover:bg-green-700 text-white font-semibold rounded-2xl transition active:scale-[0.985]">
                        Поставить роспись
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
let currentModalDocId = null;

function openRospisModal(docId, title, hasQuiz, quizId, docUrl, isSigned) {
    const modal = document.getElementById('rospis-modal');
    const titleEl = document.getElementById('modal-title');
    const docLink = document.getElementById('modal-doc-link');
    const quizSection = document.getElementById('modal-test-section');
    const quizLink = document.getElementById('modal-quiz-link');
    const form = document.getElementById('modal-sign-form');
    const docIdInput = document.getElementById('modal-doc-id');

    titleEl.textContent = title;
    docLink.href = docUrl || '#';
    docIdInput.value = docId;
    currentModalDocId = docId;

    // Quiz
    if (hasQuiz && quizId) {
        quizSection.style.display = 'block';
        quizLink.href = `/quiz/${quizId}`;
    } else {
        quizSection.style.display = 'none';
    }

    // Form
    form.action = `/rosisi/sign/${docId}`;
    form.reset();

    if (isSigned) {
        form.style.opacity = '0.6';
        form.querySelector('button').disabled = true;
        form.querySelector('button').textContent = 'Роспись уже поставлена';
    } else {
        form.style.opacity = '1';
        form.querySelector('button').disabled = false;
        form.querySelector('button').textContent = 'Поставить роспись';
    }

    modal.classList.remove('hidden');
    modal.classList.add('flex');
}

function closeRospisModal() {
    const modal = document.getElementById('rospis-modal');
    modal.classList.remove('flex');
    modal.classList.add('hidden');
}

// Close on Escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const modal = document.getElementById('rospis-modal');
        if (!modal.classList.contains('hidden')) closeRospisModal();
    }
});
</script>
@endsection
