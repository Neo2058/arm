@extends('documents.view')

@section('content')
    <div id="quiz-player"></div>

    <script>
        // Laravel превратит это в: window.__QUIZ_DATA__ = {"id":1, "title":"..."};
        window.__QUIZ_DATA__ = @js($quiz);
    </script>


    @viteReactRefresh
    @vite(['resources/js/app.jsx'])
@endsection
