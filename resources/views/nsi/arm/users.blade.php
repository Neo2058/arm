@extends('nsi.layouts.nsi')
@section('content')
    <h1 class="text-2xl font-bold mb-1">Пользователи АРМ</h1>
    <p class="text-sm text-orange-600 mb-6">Роли портала: нарядчик, оператор учёта, администратор. Пароли и создание учёток — в Filament /admin, не в DOS USERS.DBF.</p>
    <div class="bg-white dark:bg-[#0b1018] border rounded-3xl overflow-hidden">
        <table class="w-full text-sm">
            <thead class="text-left text-xs text-orange-500 border-b">
            <tr>
                <th class="px-4 py-2">Имя</th>
                <th class="px-4 py-2">Почта</th>
                <th class="px-4 py-2">Роль</th>
            </tr>
            </thead>
            <tbody>
            @foreach($users as $user)
                <tr class="border-t border-gray-100 dark:border-white/10">
                    <td class="px-4 py-2">{{ $user->name }}</td>
                    <td class="px-4 py-2">{{ $user->email }}</td>
                    <td class="px-4 py-2">{{ $user->roleEnum()->label() }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
@endsection
