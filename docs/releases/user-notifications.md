# Оповещения: чат, формуляр, поручения

17.11.2026. Выкладка: миграция `user_notifications` / `push_subscriptions`, PHP и APK 1.1.11 / versionCode 27. Мониторный APK не меняется.

## Что получает сотрудник

- Новое сообщение в чате колонны/руководства/рабочей комнаты: заголовок «Новое сообщение в чате «…»», без текста переписки.
- Новая запись в формуляр: инструкторы той же колонны и super_admin, без текста записи.
- Решение по формуляру (подтверждена / возвращена): машинист, без комментария инструктора.
- Поручение: назначено / отчёт отправлен / принято / возвращено. Это существующий контур `DisciplineService`, отдельного бюллетеня инструктора нет.

Оповещение приходит, когда вкладка браузера закрыта (если заданы VAPID-ключи и браузер принял Web Push) и когда Android-приложение свёрнуто или не на экране (после установки 1.1.11). Пока процесс жив, шторка не дублирует уже видимый список.

## Как устроено

Источник истины — таблица `user_notifications`. Непрочитанные сообщения одной комнаты сливаются в одну строку (`count` растёт). Остальные типы всегда вставляются новой строкой.

Доставка:

- Веб: колокольчик на рабочих раскладках, страница `/notifications`, опрос `/notifications/inbox.json` каждые 30 с на открытой вкладке. Service worker `public/sw-push.js` по пустому Push-пингу сам читает inbox (тела в пинге нет).
- Android: JS-опрос только при `AppState === 'active'`; в фоне нативный сервис `ArmNotificationService` (тип `dataSync`) с токеном в `SharedPreferences`. Постоянное тихое «АРМ: уведомления включены» и отдельные события в канале высокой важности. Deep link `tch15://notifications/{id}`. После перезагрузки и замены APK сервис поднимается, если сессия сохранена. Выход из профиля останавливает сервис.
- FCM, Expo Notifications, Telegram и SMTP для этих событий не используются. `CHAT_ENCRYPTION_KEY` и текст чата в пуш не попадают.

`features.notifications` в `/api/mobile/me` следует за `NOTIFICATIONS_ENABLED`.

## Ограничения

- Чат в вебе по-прежнему отсутствует: веб-оповещение просит открыть приложение ТЧ-15.
- Без `VAPID_PUBLIC_KEY` / `VAPID_PRIVATE_KEY` закрытая вкладка браузера не получает системный пуш; открытая вкладка продолжает опрос.
- Уже установленные APK 1.1.10 и ниже не содержат foreground-сервис: шторка в свёрнутом Android появится только после установки 1.1.11. PHP-inbox и веб работают сразу после выкладки сервера.
- Мониторный пакет `ru.tch15.arm.monitor` сервис и permission не получает.
- Новых имён событий ClickHouse нет. `account.profile_updated` по-прежнему пишет только имена полей.

## Выкладка

1. Резервная копия Postgres.
2. Только миграция `2026_11_17_140000_create_user_notifications.php` административной ролью (`sudo python3 scripts/arm-admin-artisan.py migrate --force --path=...`). Runtime-роль без DDL.
3. Fast-forward кода, перезапуск `arm-app`. Vite не обязателен: JS колокольчика статический (`public/js/user-notifications.js`, `public/sw-push.js`).
4. На сервере `php artisan arm:vapid-generate`, строки в `.env`, не коммитить закрытый ключ, `--force-recreate app`.
5. Собрать и опубликовать APK 1.1.11 / code 27 тем же release-сертификатом. Не понижать versionCode. Мониторный APK не трогать.
6. Проверить `/up`, гостевой `/notifications` → 302, гостевой `/api/mobile/notifications` → 401, `/api/mobile/me` → `features.notifications`.

Откат без отката миграции: `NOTIFICATIONS_ENABLED=false` и recreate `app`. Колокольчик и API скрываются. Таблицы можно оставить.

## Проверки до выкладки

- PHPUnit: `UserNotificationTest`, `ChatApiTest`, `WebRoutesSplitTest`.
- Pint по затронутым PHP-файлам.
- Node: `mobile/tests/notification-poller.test.cjs`, `mobile/tests/chat.test.cjs`, `mobile/tests/chat-pdf-viewer.test.cjs`.
