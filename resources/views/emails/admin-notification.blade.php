<x-mail::message>
# {{ $notification->title }}

{{ $notification->message }}

@if($notification->data)
**Дополнительные данные:**
```json
{{ json_encode($notification->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}
```
@endif

@if($notification->type === 'access_request')
[Посмотреть все заявки]({{ url('/admin/access-requests') }})
@elseif($notification->type === 'device_request')
[Посмотреть заявки на устройства]({{ url('/admin/user-devices') }})
@else
[Открыть админ-панель]({{ url('/admin') }})
@endif

Спасибо,<br>
{{ config('app.name') }}
</x-mail::message>
