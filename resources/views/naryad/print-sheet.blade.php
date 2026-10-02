<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $sheet['title'] }}</title>
    <style>
        body { margin: 16px; background: #fff; color: #000; }
        .toolbar { margin-bottom: 16px; font-family: system-ui, sans-serif; }
        .toolbar a, .toolbar button {
            display: inline-block; margin-right: 8px; padding: 6px 12px;
            border: 1px solid #ccc; background: #f4f4f4; border-radius: 8px;
            text-decoration: none; color: #111; font-size: 14px; cursor: pointer;
        }
        @media print {
            .toolbar { display: none; }
            body { margin: 8mm; }
        }
        @page { size: A4 portrait; margin: 10mm; }
    </style>
</head>
<body>
    <div class="toolbar">
        <button type="button" onclick="window.print()">Печать</button>
        <a href="{{ route('naryad.index') }}">К сетке</a>
    </div>
    @include('naryad.partials.print-sheet-body', ['sheet' => $sheet, 'user' => $user])
    @if (!empty($autoPrint))
        <script>window.addEventListener('load', function () { window.print(); });</script>
    @endif
</body>
</html>
