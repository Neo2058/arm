@php
 $formId=$card?->id ?? 'new';
 $restore=(string) old('form_id')===(string) $formId;
 $selected=$restore ? (array)old('participants',[]) : ($card ? $members->get($card->id,collect())->pluck('user_id')->all() : []);
 $selected=array_map('strval',$selected);
 $available=$crew->pluck('id')->map(fn($id)=>(string)$id)->all();
@endphp
<form method="POST" action="{{ $card ? route('journal.training-requests.update',$card->id) : route('journal.training-requests.store') }}" class="space-y-4" data-training-form>
 @csrf
 @if($card) @method('PATCH') <input type="hidden" name="version" value="{{ $restore ? old('version',$card->version) : $card->version }}"> @endif
 <input type="hidden" name="operation_id" value="{{ $restore ? old('operation_id',(string) Illuminate\Support\Str::uuid()) : (string) Illuminate\Support\Str::uuid() }}">
 <input type="hidden" name="form_id" value="{{ $formId }}">
 <label class="block">Месяц планирования<input class="block border rounded p-2 bg-white dark:bg-gray-900" type="month" name="planning_month" required value="{{ $restore ? old('planning_month') : ($card ? substr($card->planning_month,0,7) : $month) }}"></label>
 <label class="block">Тип смены<input class="block border rounded p-2 w-full bg-white dark:bg-gray-900" name="shift_type" maxlength="32" placeholder="Например, А2" required value="{{ $restore ? old('shift_type') : $card?->shift_type }}"></label>
 <label class="block">Количество участников<input class="block border rounded p-2 bg-white dark:bg-gray-900" type="number" name="participant_count" min="1" required value="{{ $restore ? old('participant_count') : $card?->participant_count }}"></label>
 <fieldset class="border rounded p-3"><legend>Машинисты колонны {{ $column }}</legend>
 <label class="block mb-2">Поиск<input type="search" class="block border rounded p-2 w-full bg-white dark:bg-gray-900" data-member-search placeholder="Фамилия или табельный номер"></label>
 <div class="max-h-56 overflow-auto">
 @foreach($crew as $member)
 <label class="block py-1" data-member-option><input type="checkbox" name="participants[]" value="{{ $member->id }}" @checked(in_array((string)$member->id,$selected,true))> <span>{{ $member->name }} · {{ $member->profile?->tab_number }}</span></label>
 @endforeach
 </div>
 @foreach($selected as $selectedId)
 @if(!in_array($selectedId,$available,true))
 <p class="text-red-600">Участник {{ $members->get($card?->id,collect())->firstWhere('user_id',(int)$selectedId)?->name ?? '#'.$selectedId }} больше недоступен. Уточните состав.</p>
 @endif
 @endforeach
 </fieldset>
 <div aria-live="polite"><strong>Выбранные машинисты (<span data-selected-count>0</span>)</strong><ul data-selected-list class="list-disc pl-5"></ul></div>
 <button class="bg-orange-500 text-white rounded px-4 py-2" type="submit">Сохранить карточку</button>
</form>
