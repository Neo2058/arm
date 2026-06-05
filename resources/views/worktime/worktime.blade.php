@extends('teaching.layouts.index')

@section('content')

    <div class="flex flex-col lg:flex-row min-h-screen bg-[#0b1018] text-white w-full max-w-full m-0 p-0 overflow-x-hidden">
        <!-- Главная область: сюда React смонтирует наш космический календарь -->
        <main class="flex-1 p-2 md:p-6 min-w-0 w-full bg-[#0b1018]">
            <div class="w-full pt-16 lg:pt-4">

                <!-- Контейнер для монтирования React-приложения календаря -->
                <div id="work-calendar-root"></div>

            </div>
        </main>
    </div>

@endsection
