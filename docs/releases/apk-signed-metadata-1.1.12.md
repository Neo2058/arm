# Подпись выпуска APK, дни недели и лимитер Telegram — Android 1.1.12 / code 28

20.11.2026. **Опубликовано на production.** Серверный checkout `ee1d2ec4b7e7eaa43a76d9a7107ac5c1c6000410`. Мониторный APK остаётся сборкой от 06.11.2026 (`55293004` байт).

## Что получает сотрудник

- Календарь учёта смен: дни недели Пн–Вс, 1-е число в своей колонке. Подтверждённые выходные с зелёным фоном, смены оранжевые.
- Привязка Telegram: отдельный лимитер 5 попыток / 10 минут. При превышении показывается, сколько подождать. Опрос inbox и чата этим лимитером не режется.
- Автообновление с 1.1.12: приложение само считает SHA-256 скачанного APK, проверяет пакет `ru.tch15.arm`, сертификат ТЧ-15 и ECDSA-подпись метаданных. SHA-256 на странице `/install` сотруднику не показывается.
- Страница `/install` показывает версию 1.1.12 и краткий список изменений.

Уже установленные 1.1.6–1.1.11 обновляются по `version_code`. Проверку подписи, хеша и сертификата они выполняют после установки 1.1.12.

## Как устроено

Публичный `GET /install/android.json` отдаёт поля `certificate_sha256`, `changes`, `package`, `sha256`, `signature`, `size`, `url`, `version`, `version_code`. PHP сверяет SHA-256 файла на диске с JSON, проверяет подпись открытым ключом из `config/install.php` и при несовпадении отвечает 404. `published_at` и `publisher_git_commit` в ответ не входят.

JSON подписывается ECDSA P-256 (SHA256withECDSA) на машине публикации. Закрытый ключ метаданных хранится в `~/.tch15-arm/update-metadata.pem` (режим 600) и на production не копируется. Открытый SPKI закреплён в `config/install.php`, в клиенте и в публикаторе.

Публикатор подписывает манифест до замены APK. Пакет Python `cryptography` обязателен. Новый unsigned JSON на живом PHP 1.1.12 даёт 404.

## Android

- Пакет `ru.tch15.arm`, 1.1.12 / versionCode 28.
- Размер **49 114 854** байт.
- SHA-256 `e31a02ba1ca17e3d4545119d308b9df2db72b3175cf06b0bf1956c742e9fa120`.
- Сертификат выпуска `2d0cdbf6e20385dfab263e4b09cc0729fbbb6fa80074866913df8bd8c9e3c99f`.
- Предыдущий установщик 1.1.11 / code 27 сохранён в `/home/deploy/releases/signed-metadata-1.1.12-20261120T0825Z/`.

## Проверки до выкладки

- PHPUnit: InstallPageTest, AndroidReleaseMetadataTest, MobileTelegramAccountTest, TelegramBotsTest (32 теста / 206 assertions после pint).
- Node: `mobile/tests/app-update.test.cjs` (6), `worktime.test.mjs` (21), `chat-pdf-viewer.test.cjs` (4). В `app-update.cjs` нет `require('crypto')`: Metro его не бандлит, подпись проверяет native `verifyReleaseSignature`.
- Python: `mobile/tests/test_publish_installers.py` (14).
- Локальный `assembleRelease` 1.1.12 / code 28, apksigner v2, пакет и сертификат совпали.

Новых миграций нет.

## Выкладка

- Коммит `ee1d2ec` в `origin/main`; video/desktop/security/economics-план не входили.
- Fast-forward production `93586c8` → `ee1d2ec` без reset/clean. Filament CSS/JS и untracked `ssl/`, `public/vendor/livewire/`, `Dockerfile.pre-php8235` сохранены.
- APK и подписанный `android-latest.json` заменены атомарно до переключения PHP. Старый PHP отдавал 1.1.12 без полей подписи; после restart новый PHP проверяет подпись.
- Vite: архив `/tmp/arm-vite-112.tgz` SHA-256 `03b858b8872b50f033fdd9cd93012bda73ddeb3f007601b9ab37acdc5e1ed92c`. Новые hash-файлы `app-BqcL0zOS.css`, `app-hYKFQCTk.js`, `app-BlidiKx8.css`; `client-dgengZBm.js` и `carousel-bIdUHhke.js` совпали с уже лежащими. `manifest.json` заменён атомарно, прежние hash-файлы оставлены.
- `chmod 644` новых PHP, каталог `app/Services/Install` 755. `docker restart arm-app` → healthy. `QUEUE_CONNECTION`: `arm-app` deferred, `arm-chat-worker` database. `COMPOSE_FILE` прежний. Webhook ключей не трогался. Закрытый ключ метаданных на сервере отсутствует.

## Фактические результаты

- `/up` `/login` `/admin/login` `/install` — 200. Гостевой `/notifications` — 302. Гостевой `GET /api/mobile/notifications` и `/api/mobile/me` с `Accept: application/json` — 401.
- `GET /install/android.json` — 200, `Cache-Control: no-store, private`, `version_code` 28, размер 49114854, подпись 144 hex, сертификат ТЧ-15, 4 пункта изменений. Локальная проверка ECDSA по закреплённому открытому ключу прошла.
- `/install/android`: `application/vnd.android.package-archive`, Range 206, ZIP magic `PK`, полное HTTPS-скачивание SHA-256 `e31a02ba1ca17e3d4545119d308b9df2db72b3175cf06b0bf1956c742e9fa120`.
- `arm-app` healthy, `arm-chat-worker` running.

Ручная приёмка: установить 1.1.12 с `/install` или принять внутриприложенное обновление с 1.1.11; открыть календарь смен (Пн–Вс, зелёный выходной); при исчерпании попыток привязки Telegram увидеть паузу.

Откат установщика: вернуть APK и JSON из `/home/deploy/releases/signed-metadata-1.1.12-20261120T0825Z/` и перезапустить `arm-app`. Откат PHP на код до `ee1d2ec` снова отдаст JSON без проверки подписи.
