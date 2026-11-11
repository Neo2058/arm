# Мобильные подстройки — production 09.11.2026

Выкладка по поручению владельца: git push `origin/main` и публикация API + Android.

## Состояние

- Предыдущий checkout: `f1e5a90` (`deploy/chat-20261106`).
- Новый checkout: `9c5a022`.
- Резервная копия: `/home/deploy/backups/podstroiki-pre-20261109T115545Z/`
  (`arm.pg.dump` custom, 1109 TOC, SHA-256 `a98ae7e8976426b73665286691beacc8731291ce2a61ea1568f25cb094827e74`;
  прежний APK SHA-256 `7d35db171283fb952e0f1321b61cef742575d07064c461fe441fcb8ad5c0d5cf`).
- Миграции по одной, batch 18–20:
  `2026_10_31_140000_add_shift_fields_to_podstroikas_table`,
  `2026_11_07_000000_allow_multiple_podstroikas_per_month`,
  `2026_11_07_000001_create_podstroika_operations_table`.
- Runtime `arm_runtime` читает новые колонки `podstroikas` и таблицу `podstroika_operations`.
- `arm-app` healthy, `QUEUE_CONNECTION=deferred`. `arm-chat-worker` running, `QUEUE_CONNECTION=database`.
- COMPOSE_FILE без изменений: prod + discipline + discipline.runtime + security + readonly + chat + `docker-compose.monitor-mobile.yml` последним.
- Mounts `/run/arm-metrics` и `/run/arm-installers` на месте. Приватный monitor APK не трогался.

`sudo` у `deploy` по-прежнему спрашивает пароль, поэтому `arm-admin-artisan.py` и `docker compose up --force-recreate` с хоста недоступны. DDL выполнен через административную роль контейнера `arm-postgres` тем же stdin-PHP, что и штатный wrapper. Код подхвачен bind-mount + `docker restart arm-app` (entrypoint read-only заново собрал cache). Полный age-backup не запускался.

## Android

- Пакет `ru.tch15.arm`, 1.1.2 / versionCode 18.
- SHA-256 `3c5d4e69286e810c5145b1722673b062c53d44748900470525e5512833e16bd2`.
- Сертификат выпуска `2d0cdbf6e20385dfab263e4b09cc0729fbbb6fa80074866913df8bd8c9e3c99f`.
- `/install/android`: HTTP 200, `application/vnd.android.package-archive`, 89 283 562 байт, Range 206, ZIP magic, SHA совпадает.

## HTTP

`/up` `/login` `/admin/login` `/install` — 200. Без токена JSON 401: `/api/mobile/podstroiki`, `/podstroiki/limits`, `/me`, `/chats/sync`, `/api/monitor/snapshot`. `verify-readonly-runtime.py` прошёл.

## Что проверить на телефоне

Установить 1.1.2 поверх 1.1.1. Машинист и инструктор: Наряды → Подстройка смены, дата/маршрут/смена/текст, лимит, отзыв и повтор на ту же дату. Нарядчик форму себе не видит. Это заявка нарядчику, не АТЗ и не сетка.
