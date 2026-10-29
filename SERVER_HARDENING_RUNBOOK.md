# Эксплуатация изолированного runtime ARM

Применено 28 октября 2026. Overlay `docker-compose.security.yml` применяется
последним после prod/discipline/runtime. Требуется Docker Compose >= 2.30
(используется raw env_file) и Docker Engine с volume subpath.

## Секреты и запуск

В production `COMPOSE_FILE` в `.env` включает security overlay. Файлы
`/etc/arm/services/{app,postgres,minio,clickhouse,meilisearch}.env` принадлежат
root, имеют права 0600, каталог 0700. Значения не коммитить и не печатать через
`docker compose config`/`docker inspect`; для проверки использовать `config --quiet`.
В `.env` проекта теперь только runtime DB/S3 credentials; административные
DB/MinIO credentials находятся в отдельных host-only файлах соответствующих служб.

Compose с overlay запускать через sudo из `/home/deploy/arm`: deploy не должен
читать сервисные файлы напрямую. Не запускать только `-f docker-compose.prod.yml`:
это возвращает прежние env/mounts и неверно назначает runtime S3 key корневым MinIO.
Инициализация MinIO/БД выполняется административно; runtime не создаёт bucket.

`app.env` — снимок рабочего окружения, а не автоматически обновляемая ссылка на `.env`.
При изменении настроек приложения администратор должен синхронно обновить app.env,
сохранив runtime DB/S3 credentials и `ARM_BUCKET_PROVISIONED=true`, затем пересоздать
только app и пересобрать config cache. Не копировать в него административные
секреты PostgreSQL/MinIO. Не менять APP_KEY мимоходом.

## Миграции

Приложение использует `arm_runtime`: DML текущих таблиц и sequences, без DDL,
SUPERUSER/CREATEDB/CREATEROLE/REPLICATION/BYPASSRLS. Владельцем схемы и существующих
объектов остаётся прежняя административная роль. Default privileges настроены
для её будущих таблиц/sequences в public.

Проверка состояния (не изменяет схему):

```sh
cd /home/deploy/arm
sudo python3 scripts/arm-admin-artisan.py migrate:status
```

Применение только заранее просмотренных миграций с backup и проверкой rollback:

```sh
sudo python3 scripts/arm-admin-artisan.py migrate --force
```

Wrapper передаёт host-only DB credentials в отдельный PHP-процесс через stdin,
не через аргументы/глобальное окружение, и не меняет runtime config cache.
Не запускать с ним `config:cache`; wrapper намеренно разрешает только две команды.
Не менять владельца объектов/роль мигратора без обновления default privileges.

## Границы защиты

MinIO service account ограничен рабочим bucket: чтение/запись/удаление объектов,
list/multipart. Это защищает административные функции и прочие bucket, но RCE
приложения всё ещё позволяет прочитать или удалить доступные приложению документы.
Равным образом runtime DB роль имеет доступ к необходимым пользовательским данным.
Эти меры уменьшают полномочия, не обеспечивают секретность при полном захвате runtime.

Nginx получает public read-only, только подкаталог app/public storage-volume,
конфигурацию и TLS. Полный checkout, Laravel config cache, .git и private storage
не примонтированы. App/Nginx имеют no-new-privileges. Весь код app пока не переведён
на read-only: это следующий самостоятельный этап с изменением startup/deploy.
Backend network пока общая; SSH/firewall/Fail2Ban этим пакетом не изменены.

Служебные логи ClickHouse хранятся один календарный месяц: read-only конфигурация
`docker/clickhouse/system-log-retention.xml` в security overlay. Аналитика
`default.user_actions` не затронута. При изменении схем/версии проверять TTL,
колонки времени и возможные переименованные system-log tables после запуска.

## Откат и копии

Приватный backup: `/root/arm-hardening-data-20261028`: исходные env/compose,
Docker metadata, pg_dump с проверенным оглавлением, rollback.sh и журналы.
Metadata содержит секреты: не публиковать, не прикладывать к тикетам.
Rollback возвращает исходные credentials/env/mounts и прежние runtime-права;
применять только для восстановления сервиса, после этого повторно закрыть риски.
Он НЕ восстанавливает БД поверх новых данных.

В первом переключении обнаружен отсутствующий executable bit startup-скрипта:
исправлен mode 0755; был временный отказ приложения. Таймер второго этапа
сработал до окончания проверок и вернул старую конфигурацию. Финальное переключение
проведено повторно после исправлений, проверено; финальный таймер отменён.

Не удалять прежнюю роль/MinIO administrator до проверки backup и новой схемы
администрирования. Наличие локального backup не заменяет внешние immutable copies.
