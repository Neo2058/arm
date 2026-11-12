# Личный табель и автообновление — Android 1.1.6 / code 22

Статус: исходники и signed APK готовы локально. Выкладка PHP и `/install` — по поручению владельца 12.11.2026.

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
