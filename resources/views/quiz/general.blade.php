@extends('teaching.layouts.index')

@section('content')
    <div id="general-quiz-player"></div>

    <script>
        // Передаем объект напрямую в память браузера без прослойки в виде HTML-атрибутов
        window.__GENERAL_QUIZ_DATA__ = @js($quiz);
    </script>
@endsection
