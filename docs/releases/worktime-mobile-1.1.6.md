# Личный табель и автообновление — Android 1.1.6 / code 22

Статус: серверная часть и APK выложены 12.11.2026. Runtime-коммит `ec4d182`. Физическая установка 1.1.6 на телефоны ещё нужна один раз со страницы `/install`.

## Изменения

- DELETE своей записи v2 (смена или отсутствие) через `WorkTimeMutationService` с UUID и `expected_revision`; повтор квитанции, ClickHouse `work_shift.saved` с `deleted`.
- Календарный день взаимно исключает смену, выходной и отсутствие. Несколько рабочих сегментов в один день остаются допустимы. Ночная смена занимает даты от начала до минуты перед окончанием.
- Веб-остров и мобильный экран: кнопка «Удалить запись», выходной нельзя включить на занятый день.
- Автообновление основного APK: публичный `GET /install/android.json`, скачивание `/install/android` без токена, проверка размера, системный установщик. Подтверждение Android обязательно. Уже стоящие сборки до 1.1.6 этот запрос не делают: 1.1.6 ставят один раз со страницы установки.
- Публикатор пишет `android-latest.json` вместе с APK. Мониторный пакет `ru.tch15.arm.monitor` этим путём не обновляется. Новых миграций нет.

## Android

- Пакет `ru.tch15.arm`, 1.1.6 / versionCode 22.
- SHA-256 `13a0953d16028b6fb32423c76f0ba4e934d7575b915190426c5cee6f0751ae9b`.
- Сертификат выпуска `2d0cdbf6e20385dfab263e4b09cc0729fbbb6fa80074866913df8bd8c9e3c99f`.
- Размер 49 045 410 байт.

## Проверки до выкладки

- PHP: PersonalWorkTimeApiTest, PersonalWorkAbsenceTest, InstallPageTest, WebRoutesSplitTest.
- Node: worktime, app-update, chat-pdf-viewer.
- Python: `mobile/tests/test_publish_installers.py`.
- Локальный `assembleRelease`; в бандле есть `/install/android.json`, `tch15-update.apk`, «Удалить запись».

Приватный monitor APK, Telegram-конфигурация, MinIO и схема PostgreSQL не меняются.

## Выкладка

- [x] Коммит `ec4d182` в `origin/main`; video/desktop-план не входили.
- [x] Fast-forward production `44b958a` → `ec4d182` без reset/clean. Прежний APK сохранён в `/home/deploy/releases/worktime-20261112-ec4d182/`.
- [x] Веб-ресурсы доставлены, `manifest.json` заменён атомарно; `arm-app` healthy. Новых миграций нет.
- [x] APK и `android-latest.json` опубликованы атомарно после готовности API.
- [ ] Установка 1.1.6 с `/install` на физических телефонах и проверка удаления смены / баннера обновления на следующей сборке.

## Фактические результаты

- `/up` `/login` `/admin/login` `/install` — 200. Гостевой `GET /api/mobile/worktime/month` и `DELETE /api/mobile/worktime/shifts/1` — 401. Веб `DELETE /api/worktime/v2/shifts/1` — 419 CSRF.
- `GET /install/android.json` — 200, `Cache-Control: no-store, private`, `version_code` 22, размер 49045410, без `publisher_git_commit`.
- `/install/android`: Content-Type `application/vnd.android.package-archive`, Range 206, ZIP magic, полное скачивание SHA-256 `13a0953d16028b6fb32423c76f0ba4e934d7575b915190426c5cee6f0751ae9b`.
- `arm-chat-worker` работает, `QUEUE_CONNECTION=database`. На `arm-app` по-прежнему `deferred`. Monitor APK от 06.11.2026 не менялся.
