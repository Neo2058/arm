(function () {
  var script = document.currentScript;
  if (!script) return;
  var inboxUrl = script.getAttribute('data-inbox') || '/notifications/inbox.json';
  var subscribeUrl = script.getAttribute('data-subscribe') || '/notifications/push-subscription';
  var unsubscribeUrl = script.getAttribute('data-unsubscribe') || '/notifications/push-subscription';
  var vapid = script.getAttribute('data-vapid') || '';
  var swUrl = script.getAttribute('data-sw') || '/sw-push.js';
  var pollMs = 30000;

  function csrf() {
    var hidden = document.getElementById('arm-notify-csrf');
    if (hidden && hidden.value) return hidden.value;
    var meta = document.querySelector('meta[name="csrf-token"]');
    if (meta && meta.content) return meta.content;
    var match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
  }

  function headers(json) {
    var out = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
    var token = csrf();
    if (token) out['X-CSRF-TOKEN'] = token;
    if (json) out['Content-Type'] = 'application/json';
    return out;
  }

  function setBadge(count) {
    var n = Number(count) || 0;
    var bell = document.getElementById('arm-notify-bell');
    var badge = document.getElementById('arm-notify-count');
    var page = document.getElementById('arm-notify-page-unread');
    if (bell) bell.setAttribute('data-count', String(n));
    if (badge) badge.textContent = n > 99 ? '99+' : String(n);
    if (page) page.textContent = String(n);
    if (bell) bell.setAttribute('aria-label', n > 0 ? 'Уведомления, непрочитанных ' + n : 'Уведомления');
  }

  function refresh() {
    fetch(inboxUrl + (inboxUrl.indexOf('?') >= 0 ? '&' : '?') + 'unread=1', {
      credentials: 'same-origin',
      headers: headers(false),
    }).then(function (res) {
      if (!res.ok) return null;
      return res.json();
    }).then(function (data) {
      if (data && typeof data.unread === 'number') setBadge(data.unread);
    }).catch(function () {});
  }

  function urlBase64ToUint8Array(base64String) {
    var padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    var raw = atob((base64String + padding).replace(/-/g, '+').replace(/_/g, '/'));
    var output = new Uint8Array(raw.length);
    for (var i = 0; i < raw.length; i += 1) output[i] = raw.charCodeAt(i);
    return output;
  }

  function registerPush() {
    if (!vapid || !('serviceWorker' in navigator) || !('PushManager' in window)) return;
    if (!window.isSecureContext) return;
    navigator.serviceWorker.register(swUrl, { scope: '/' }).then(function (reg) {
      return Notification.requestPermission().then(function (permission) {
        if (permission !== 'granted') return null;
        return reg.pushManager.getSubscription().then(function (existing) {
          return existing || reg.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: urlBase64ToUint8Array(vapid),
          });
        });
      });
    }).then(function (sub) {
      if (!sub) return;
      return fetch(subscribeUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: headers(true),
        body: JSON.stringify(sub.toJSON()),
      });
    }).catch(function () {});
  }

  refresh();
  setInterval(refresh, pollMs);
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'visible') refresh();
  });
  if (document.readyState === 'complete') registerPush();
  else window.addEventListener('load', registerPush);
})();
