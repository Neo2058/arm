@extends('teaching.layouts.index')
@section('content')
<div class="max-w-6xl mx-auto px-4 py-6 text-gray-900 dark:text-gray-100">
 <a href="{{ route('journal.index') }}" class="underline">Рабочий журнал</a>
 <h1 class="text-2xl font-bold mt-4">Подстройки АТЗ</h1>
 <p>Колонна {{ $column }} · Карточек на месяц: {{ $used }} / 10</p>
 <p class="text-sm my-3">Заявки на планирование аварийно-тренировочных занятий. Сохранение карточки не означает выполнения норматива или доставки нарядчику.</p>
 @if(session('success')) <p class="p-3 bg-green-100 text-green-900 rounded" role="status">{{ session('success') }}</p> @endif
 @if($errors->any()) <div role="alert" class="p-3 bg-red-100 text-red-900 rounded"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div> @endif
 <form method="GET" class="my-4"><label>Месяц <input class="border rounded p-2 bg-white dark:bg-gray-900" type="month" name="month" value="{{ $month }}" required></label><button class="border rounded px-3 py-2">Показать</button></form>
 <details class="border rounded-xl p-4 my-4" @if(old('form_id')==='periods') open @endif>
 <summary>Периодичность АТЗ</summary>
 <form method="POST" action="{{ route('journal.training-requests.periods') }}" class="space-y-3 mt-3">
 @csrf <input type="hidden" name="form_id" value="periods">
 <input type="hidden" name="operation_id" value="{{ old('form_id')==='periods' ? old('operation_id') : (string)Illuminate\Support\Str::uuid() }}">
 @foreach(['atz'=>'АТЗ','atz_line'=>'АТЗ на линии'] as $type=>$label)
 <label class="block">{{ $label }} — раз в <input type="number" min="1" max="24" required name="{{ $type }}_months" value="{{ old('form_id')==='periods' ? old($type.'_months') : ($setting?->{$type.'_months'} ?? 12) }}" class="border rounded p-2 w-20 bg-white dark:bg-gray-900"> месяцев</label>
 @endforeach
 <p class="text-sm">Изменение периода пересчитывает следующий срок от последнего выполнения. История занятий сохраняется.</p>
 <button class="bg-orange-500 text-white rounded px-4 py-2">Сохранить периодичность</button>
 </form></details>
 <details class="border rounded-xl p-4 my-4"><summary>Учёт нормативов АТЗ по колонне</summary>
 <div class="overflow-x-auto"><table class="w-full text-sm"><thead><tr><th>Машинист</th><th>Вид</th><th>Последнее выполнение</th><th>Следующий срок</th></tr></thead><tbody>
 @foreach($crew as $driver)
 @foreach(['atz'=>'АТЗ','atz_line'=>'АТЗ на линии'] as $type=>$label)
 @php $norm=$atzNorms->get($driver->id,collect())->firstWhere('type',$type); @endphp
 <tr class="border-b"><td class="p-2">{{ $driver->name }}</td><td>{{ $label }}</td><td>{{ $norm?->last_date?->format('d.m.Y') ?? 'Не отмечено' }}</td><td>{{ $norm?->next_date?->format('d.m.Y') ?? '—' }} @if($norm?->next_date?->lt(today()))<strong class="text-red-600">Просрочено</strong>@endif</td></tr>
 @endforeach
 @endforeach
 </tbody></table></div></details>
 <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
 @foreach($cards as $card)
 <section class="p-4 border rounded-xl bg-white dark:bg-gray-800">
 <h2 class="font-bold mb-2">{{ $card->shift_type }} · {{ $card->participant_count }} участников</h2>
 <ul class="mb-3">@foreach($members->get($card->id,collect()) as $member)<li>{{ $member->name }}</li>@endforeach</ul>
 @php $completion=$completions->get($card->id); @endphp
 @if($completion)
 <div class="my-3 p-3 border rounded"><strong>Занятие учтено {{ \Carbon\Carbon::parse($completion->performed_on)->format('d.m.Y') }} · {{ $completion->normative_type==='atz' ? 'АТЗ' : 'АТЗ на линии' }}</strong>
 <p>Присутствовали:</p><ul>@foreach($attendance->get($completion->id,collect()) as $present)<li>{{ $present->name }} · {{ $present->tab_number }}</li>@endforeach</ul>
 <p class="text-sm">На момент проведения период: {{ $completion->period_months }} мес. Подтверждённая запись сохраняется в истории.</p></div>
 @else
 <details class="my-3" @if(old('form_id')==='complete-'.$card->id) open @endif><summary>Отметить проведённое занятие</summary>
 <form method="POST" action="{{ route('journal.training-requests.complete',$card->id) }}" class="space-y-3 mt-3">
 @csrf <input type="hidden" name="form_id" value="complete-{{ $card->id }}">
 <input type="hidden" name="version" value="{{ old('form_id')==='complete-'.$card->id ? old('version') : $card->version }}">
 <input type="hidden" name="operation_id" value="{{ old('form_id')==='complete-'.$card->id ? old('operation_id') : (string)Illuminate\Support\Str::uuid() }}">
 <label class="block">Фактическая дата <input type="date" required max="{{ today()->toDateString() }}" name="performed_on" value="{{ old('form_id')==='complete-'.$card->id ? old('performed_on') : '' }}" class="border rounded p-2 bg-white dark:bg-gray-900"></label>
 <label class="block">Норматив <select name="normative_type" required class="border rounded p-2 bg-white dark:bg-gray-900"><option value="">Выберите вид</option><option value="atz" @selected(old('form_id')==='complete-'.$card->id && old('normative_type')==='atz')>АТЗ</option><option value="atz_line" @selected(old('form_id')==='complete-'.$card->id && old('normative_type')==='atz_line')>АТЗ на линии</option></select></label>
 <fieldset><legend>Отметьте только присутствовавших</legend>
 @foreach($members->get($card->id,collect()) as $member)
 <label class="block"><input type="checkbox" name="attendees[]" value="{{ $member->user_id }}" @checked(old('form_id')==='complete-'.$card->id && in_array((string)$member->user_id,array_map('strval',(array)old('attendees',[])),true))> {{ $member->name }}</label>
 @endforeach
 </fieldset><p class="text-sm">Будет засчитан только выбранный вид норматива отмеченным машинистам. Проверьте дату и состав до подтверждения.</p>
 <button class="bg-green-700 text-white rounded px-4 py-2">Подтвердить проведение</button>
 </form></details>
 <details @if((string)old('form_id')===(string)$card->id) open @endif><summary class="cursor-pointer mb-3">Изменить состав и смену</summary>@include('journal.training-requests.form',['card'=>$card])</details>
 <form method="POST" action="{{ route('journal.training-requests.destroy',$card->id) }}" class="mt-4">@csrf @method('DELETE')<input type="hidden" name="operation_id" value="{{ (string)Illuminate\Support\Str::uuid() }}"><input type="hidden" name="version" value="{{ $card->version }}"><input type="hidden" name="planning_month" value="{{ $month }}"><button class="text-red-600 underline">Удалить карточку</button></form>
 @endif
 </section>
 @endforeach
 </div>
 @if($used<10 || old('form_id')==='new')
 <details class="my-4 p-4 border rounded-xl" @if(old('form_id')==='new') open @endif><summary class="font-bold cursor-pointer">Добавить карточку</summary><div class="mt-4">@include('journal.training-requests.form',['card'=>null])</div></details>
 @else <p class="my-4">Лимит месяца достигнут. Можно изменить существующие карточки или выбрать другой месяц.</p> @endif
 @if($crew->isEmpty())<p>В колонне нет доступных машинистов. Обратитесь к администратору.</p>@endif
</div>
<script>
document.querySelectorAll('[data-training-form]').forEach(form=>{
 const boxes=[...form.querySelectorAll('input[type=checkbox]')];
 const render=()=>{const chosen=boxes.filter(b=>b.checked);form.querySelector('[data-selected-count]').textContent=chosen.length;const list=form.querySelector('[data-selected-list]');list.replaceChildren();chosen.forEach(box=>{const li=document.createElement('li');li.textContent=box.parentElement.querySelector('span').textContent;list.appendChild(li);});};
 boxes.forEach(b=>b.addEventListener('change',render));render();
 form.querySelector('[data-member-search]').addEventListener('input',event=>{const text=event.target.value.toLocaleLowerCase();boxes.forEach(b=>{b.parentElement.hidden=!b.parentElement.textContent.toLocaleLowerCase().includes(text);});});
});
</script>
@endsection
