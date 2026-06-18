@extends('teaching.layouts.index')

@section('content')
<div class="max-w-7xl mx-auto p-6">
    <h1 class="text-2xl font-bold mb-6">История нормативов (Колонна {{ $column ?? '—' }})</h1>

    <div class="bg-white dark:bg-gray-800 rounded-xl p-6 border">
        @if(count($history) > 0)
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="border-b">
                        <th class="p-2 text-left">Время</th>
                        <th class="p-2">Пользователь</th>
                        <th class="p-2">Тип</th>
                        <th class="p-2">Детали</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($history as $row)
                        <tr class="border-t">
                            <td class="p-2">{{ $row['event_time'] }}</td>
                            <td class="p-2">{{ $row['user_id'] }}</td>
                            <td class="p-2">{{ $row['action_type'] }}</td>
                            <td class="p-2 text-xs">{{ $row['details'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <p class="text-gray-500">История пока пуста. Выполненные нормативы будут логироваться в ClickHouse.</p>
            <p class="text-xs mt-2 text-orange-500">Для демо: используйте ClickHouseService::log('normative_completed', $userId, ['type' => '...']);</p>
        @endif
    </div>
</div>
@endsection