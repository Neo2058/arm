self.addEventListener('push', function (event) {
  event.waitUntil((async function () {
    var title = 'АРМ ТЧ-15';
    var body = 'Есть новое уведомление';
    try {
      var res = await fetch('/notifications/inbox.json?unread=1', {
        credentials: 'include',
        headers: { Accept: 'application/json' },
      });
      if (res.ok) {
        var data = await res.json();
        var first = (data.items || [])[0];
        if (first && first.title) title = first.title;
        if (data.unread > 1) body = 'Непрочитанных: ' + data.unread;
      }
    } catch (e) {}
    await self.registration.showNotification(title, {
      body: body,
      icon: '/favicon.ico',
      data: { url: '/notifications' },
    });
  })());
});

self.addEventListener('notificationclick', function (event) {
  event.notification.close();
  var url = (event.notification.data && event.notification.data.url) || '/notifications';
  event.waitUntil(clients.openWindow(url));
});
