# № карты депо Лихоборы в личном кабинете

17.11.2026. **Опубликовано на production.** Серверный checkout `7c81f67`. APK не менялся: мобильный редактор профиля читает поле из метаданных `SelfProfile`.

## Доступный результат

- В личном кабинете веба `/account` и в мобильном профиле появилась графа **№ карты депо Лихоборы**.
- То же поле на карточке `/personnel` и в Filament (пользователь и картотека).
- Хранится в `user_profiles.depot_card_number`, до 32 символов, пустое значение сбрасывается в `null`.
- `account.profile_updated` по-прежнему пишет только имена изменённых полей, без номера карты.

Отдельная сборка Android не нужна. Уже установленные APK с 1.1.6 и новее показывают поле после этой выкладки PHP.

## Проверки до выкладки

- PHPUnit: `SelfProfileTest` и `PersonnelDirectoryTest` — 18 тестов, 202 assertions.
- Pint по затронутым файлам.

## Выкладка

- Коммит `7c81f67` в `origin/main`; video/desktop-план не входили.
- Снимок перед выкладкой: `/home/deploy/backups/depot-card-pre-20261117T051417Z/`, pg_dump SHA-256 `d9643a8489184f04d478d236fda7011b21e936a8cdc54465cddf1eaf67db4c7a`, 1141 TOC.
- Исходный checkout `a61c8ff` на `deploy/podstroiki-20261109`.
- Перед переключением кода применена **только** миграция `2026_11_17_120000_add_depot_card_number_to_user_profiles.php` (batch 24). Колонка `varchar(32)` nullable; runtime-роль читает её без отдельного GRANT.
- Fast-forward `a61c8ff` → `7c81f67` без reset/clean. Перезапущен только `arm-app`, healthy. Vite и APK не трогались.

## Фактические результаты

- `/up` `/login` `/admin/login` — 200. Гостевой `/account` — 302 на `/login`. Гостевой `GET /api/mobile/account/profile` — 401.
- Runtime: колонка есть, `SelfProfile` отдаёт подпись «№ карты депо Лихоборы».
- `arm-app` `QUEUE_CONNECTION=deferred`, `arm-chat-worker` `database`.
- Публичный `/install/android.json` по-прежнему 1.1.10 / versionCode 26. Мониторный APK не менялся.

Ручная приёмка: войти в веб-кабинет и в мобильный профиль, сохранить номер карты, убедиться что он виден в картотеке.
