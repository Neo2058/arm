@extends('teaching.layouts.index')

@section('content')

    <section class="documents__viewer">
        <div id="document-viewer"></div>
        <script defer>
            window.__DOCUMENTS_TREE__ = @js($groupedDocuments);
            window.__DEVICE_OS__ = @js($deviceOs ?? 'other');
        </script>
    </section>

@endsection
