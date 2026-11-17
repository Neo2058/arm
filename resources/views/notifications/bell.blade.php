@auth
@if(filter_var(config('notifications.enabled'), FILTER_VALIDATE_BOOLEAN))
<style>
#arm-notify-bell {
    position: fixed;
    top: 16px;
    right: 16px;
    z-index: 80;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 44px;
    height: 44px;
    border-radius: 14px;
    background: #c2410c;
    color: #fff;
    text-decoration: none;
    box-shadow: 0 8px 24px rgba(0, 0, 0, .28);
}
#arm-notify-bell:focus-visible { outline: 3px solid #67e8f9; outline-offset: 3px; }
#arm-notify-count {
    position: absolute;
    top: -6px;
    right: -6px;
    min-width: 18px;
    height: 18px;
    padding: 0 5px;
    border-radius: 999px;
    background: #facc15;
    color: #111827;
    font: 700 11px/18px system-ui, sans-serif;
    text-align: center;
}
#arm-notify-bell[data-count="0"] #arm-notify-count { display: none; }
</style>
<input type="hidden" id="arm-notify-csrf" value="{{ csrf_token() }}">
<a href="{{ route('notifications.index') }}" id="arm-notify-bell" data-count="0" aria-label="Уведомления">
    <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
        <path d="M15 17h5l-1.4-1.4A2 2 0 0 1 18 14.2V11a6 6 0 1 0-12 0v3.2c0 .5-.2 1-.6 1.4L4 17h5"/>
        <path d="M9 17a3 3 0 0 0 6 0"/>
    </svg>
    <span id="arm-notify-count">0</span>
</a>
<script
    src="{{ asset('js/user-notifications.js') }}"
    defer
    data-inbox="{{ route('notifications.inbox') }}"
    data-subscribe="{{ route('notifications.subscribe') }}"
    data-unsubscribe="{{ route('notifications.unsubscribe') }}"
    data-vapid="{{ config('notifications.vapid.public_key') }}"
    data-sw="{{ asset('sw-push.js') }}"
></script>
@endif
@endauth
