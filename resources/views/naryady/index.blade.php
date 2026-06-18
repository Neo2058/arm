@extends('teaching.layouts.index')

@section('content')
<div class="max-w-7xl mx-auto px-3 sm:px-4 py-5">
    <div id="naryad-viewer"></div>

    <script defer>
        window.__NARYADS__ = @js($naryads);
        window.__IS_ADMIN__ = @js($isAdmin ?? false);
    </script>
</div>
@endsection
