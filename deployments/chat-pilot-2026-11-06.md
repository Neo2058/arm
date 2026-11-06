# Пилот чата на production — 06.11.2026

Выкладка после проверки закрытого мониторинга. Мониторинг не выключался и не переустанавливался.

## Состояние

- Предыдущий checkout: `d72a754` (`deploy/personnel-current-20261106`, ветка мониторинга).
- Новый checkout: `f1e5a90` (`deploy/chat-20261106`, origin/main).
- Резервная копия Postgres: `/home/deploy/backups/chat-pre-20261106T135019Z/arm.pg.dump` (custom, 1033 TOC, 665 users).
- Миграция `2026_10_24_120000_create_chat_tables`, batch 17. Таблицы `chat_*` и `column_deputies` выданы `arm_runtime` (SELECT/INSERT/UPDATE/DELETE).
- `CHAT_ENABLED=true`, ключ шифрования задан. `CHAT_PILOT_COLUMN` пустой.
- `arm-app` read-only, healthy. `QUEUE_CONNECTION=deferred`. `pm.max_children=15`.
- `arm-chat-worker` read-only, `QUEUE_CONNECTION=database`, `queue:work --queue=chat`.
- COMPOSE_FILE: prod + discipline + discipline.runtime + security + readonly + chat + `docker-compose.monitor-mobile.yml` последним.

## Мониторинг после recreate

- Mounts `/run/arm-metrics` и `/run/arm-installers` на месте, readable.
- Grafana/Prometheus/9108 только 127.0.0.1.
- `/api/monitor/snapshot` и `/installer` без токена — 401. Публичный `tch15-monitor.apk` — 404.
- Приватный APK root-owned 0444, SHA не менялся.
- Основной APK 1.1.1 / code17, SHA-256 `7d35db171283fb952e0f1321b61cef742575d07064c461fe441fcb8ad5c0d5cf`. Новая сборка не публиковалась.

## HTTP

`/up` `/login` `/admin/login` `/install` — 200. `/api/mobile/chats/sync` и `/api/mobile/me` без токена — 401. `verify-readonly-runtime.py` прошёл.

## Осталось вручную

В Filament «Коммуникации» создать одну комнату. Пока комнат нет, вкладка чата в 1.1.1 пустая. Приватное приложение мониторинга не переустанавливать.
