# Оповещения: чат, формуляр, поручения

17.11.2026. **Опубликовано на production.** Серверный checkout `31294e818f58a1b99a55cd1175d7b78356d08557`. APK 1.1.11 / versionCode 27. Мониторный APK не менялся.

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

- Коммит `31294e8` в `origin/main`; video/desktop-план не входили.
- Снимок перед выкладкой: `/home/deploy/backups/notifications-pre-20261117T102553Z/`, pg_dump SHA-256 `ad1d85f92aa12f9a179726455827c4dbb13cb0843148417cfe5cdd2866e416bd`, 1157 TOC.
- Исходный checkout `ba00ed0` на `deploy/podstroiki-20261109`.
- Перед переключением кода применена **только** миграция `2026_11_17_140000_create_user_notifications.php` (batch 25). Таблицы `user_notifications` и `push_subscriptions`; runtime-роли выданы SELECT/INSERT/UPDATE/DELETE и USAGE на sequences.
- Fast-forward `ba00ed0` → `31294e8` без reset/clean. Vite не собирался: JS колокольчика статический. VAPID записан в `.env`, закрытый ключ не коммитился. Перезапущены `arm-app` и `arm-chat-worker`.
- APK `ru.tch15.arm` 1.1.11 / versionCode 27, **49 105 838 байт**, SHA-256 `67295b3f847269777a90d63a51b28872abb6c307bc50b2075243c9e8bcb36e51`. Прежний release-сертификат. Предыдущий установщик сохранён в `/home/deploy/releases/user-notifications-1.1.11-20261117T103346Z/`.

## Фактические результаты

- `/up` `/login` `/admin/login` — 200. Гостевой `/notifications` — 302 на `/login`. Гостевой `GET /api/mobile/notifications` и `/api/mobile/me` с `Accept: application/json` — 401.
- Runtime читает новые таблицы, `NOTIFICATIONS_ENABLED=true`, VAPID public задан (87 символов).
- `arm-app` `QUEUE_CONNECTION=deferred`, `arm-chat-worker` `database`.
- Публичный `/install/android.json` — 1.1.11 / versionCode 27, размер и SHA совпадают с бинарником. `/install/android` — `application/vnd.android.package-archive`, полное скачивание подтвердило SHA-256, Range — 206, ZIP magic. Мониторный APK не менялся.

Откат без отката миграции: `NOTIFICATIONS_ENABLED=false` и recreate `app`. Колокольчик и API скрываются. Таблицы можно оставить.

Ручная приёмка: войти в веб, открыть колокольчик, свернуть Android 1.1.11 и получить шторку на новое сообщение чата / запись формуляра / поручение.

## Проверки до выкладки

- PHPUnit: `UserNotificationTest`, `ChatApiTest`, `WebRoutesSplitTest`.
- Pint по затронутым PHP-файлам.
- Node: `mobile/tests/notification-poller.test.cjs`, `mobile/tests/chat.test.cjs`, `mobile/tests/chat-pdf-viewer.test.cjs`.
