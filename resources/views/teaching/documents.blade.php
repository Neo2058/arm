@extends('teaching.layouts.index')

@section('content')

    <section class="documents__viewer">
        <div id="document-viewer"></div>
        <script defer>
            window.__DOCUMENTS_TREE__ = @js($groupedDocuments);
        </script>
    </section>

@endsection
