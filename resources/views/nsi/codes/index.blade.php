@extends('nsi.layouts.nsi')
@section('content')
    <h1 class="text-2xl font-bold mb-1">Кодификаторы</h1>
    <p class="text-sm text-orange-600 mb-6">KODIF + ELKODIF. В FoxPro всплывающий список показывает книги с заполненным доступом (nsisr); здесь видны все 23.</p>
    <div class="bg-white dark:bg-[#0b1018] border rounded-3xl overflow-hidden">
        <table class="w-full text-sm">
            <thead class="text-left text-xs text-orange-500 border-b">
            <tr>
                <th class="px-4 py-2">Код</th>
                <th class="px-4 py-2">Название</th>
                <th class="px-4 py-2">Доступ</th>
                <th class="px-4 py-2">Дл.кода</th>
                <th class="px-4 py-2"></th>
            </tr>
            </thead>
            <tbody>
            @forelse($books as $book)
                <tr class="border-t border-gray-100 dark:border-white/10">
                    <td class="px-4 py-2 font-mono">{{ $book->kodspr }}</td>
                    <td class="px-4 py-2">{{ $book->nazspr }}</td>
                    <td class="px-4 py-2">{{ $book->nsisr ?: '—' }}</td>
                    <td class="px-4 py-2">{{ $book->dlkodel }} / {{ $book->dlnazel }}</td>
                    <td class="px-4 py-2 text-right">
                        <a class="text-orange-600" href="{{ route('nsi.codes.show', $book) }}">элементы</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="px-4 py-6 text-orange-400">Справочники ещё не импортированы. <code>php artisan arm:import-dbf --only=codes</code></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
@endsection
