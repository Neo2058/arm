<div class="max-w-5xl">
    <h2 class="text-2xl font-bold mb-1">Печать нарядов</h2>
    <p class="text-sm text-orange-700 dark:text-orange-200 mb-4">
        Как пункт «Печать нарядов» в АРМ: наряд на выбранную дату из разбивки смен и сетки.
        Выписка — только 1-я, 3+ и 4+ смены (комната отдыха). Составы вагонов из <code>RASST</code> пока не перенесены.
    </p>

    <form id="naryad-print-form" class="bg-white dark:bg-[#0b1018] border border-gray-200 dark:border-white/10 rounded-3xl p-5 mb-5 flex flex-wrap gap-4 items-end text-sm">
        <div>
            <label class="block text-xs mb-1">Дата</label>
            <input type="date" name="date" id="naryad-print-date" value="{{ $date }}"
                   class="border rounded-2xl px-3 py-2 bg-white dark:bg-[#0b1018]">
        </div>
        <div>
            <label class="block text-xs mb-1">Вид</label>
            <select name="kind" id="naryad-print-kind" class="border rounded-2xl px-3 py-2 bg-white dark:bg-[#0b1018]">
                <option value="full" @selected($kind === 'full')>Наряд локомотивных бригад</option>
                <option value="extract" @selected($kind === 'extract')>Выписка в комнату отдыха</option>
            </select>
        </div>
        <button type="submit" class="px-5 py-2 bg-orange-600 text-white rounded-2xl font-semibold">Показать</button>
        <a id="naryad-print-open" href="{{ route('naryad.print', ['date' => $date, 'kind' => $kind]) }}"
           target="_blank"
           class="px-5 py-2 border border-orange-500/40 rounded-2xl text-orange-700 dark:text-orange-300">
            Открыть для печати
        </a>
    </form>

    <div class="bg-white text-black rounded-3xl p-6 overflow-auto border border-gray-200">
        @include('naryad.partials.print-sheet-body', ['sheet' => $sheet, 'user' => $user])
    </div>
</div>

<script>
(function () {
    const form = document.getElementById('naryad-print-form');
    const dateEl = document.getElementById('naryad-print-date');
    const kindEl = document.getElementById('naryad-print-kind');
    const openEl = document.getElementById('naryad-print-open');
    const basePartial = @json(route('naryad.partial.print'));
    const baseSheet = @json(route('naryad.print'));
    form?.addEventListener('submit', function (e) {
        e.preventDefault();
        const q = '?date=' + encodeURIComponent(dateEl.value) + '&kind=' + encodeURIComponent(kindEl.value);
        window.Naryad.loadPartial(basePartial + q);
        window.Naryad.setActive('print');
    });
    const sync = () => {
        openEl.href = baseSheet + '?date=' + encodeURIComponent(dateEl.value) + '&kind=' + encodeURIComponent(kindEl.value);
    };
    dateEl?.addEventListener('change', sync);
    kindEl?.addEventListener('change', sync);
})();
</script>
