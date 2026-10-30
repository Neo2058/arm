# Эксплуатация изолированного runtime ARM

Применено 28–29 октября 2026. После prod/discipline/runtime применяются
`docker-compose.security.yml` и последним `docker-compose.readonly.yml`.
Требуется Docker Compose >= 2.30
(используется raw env_file) и Docker Engine с volume subpath.

## Секреты и запуск

В production `COMPOSE_FILE` в `.env` включает security и readonly overlays. Файлы
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
только app: readonly entrypoint сам создаёт новый config cache. Не копировать в него административные
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
не примонтированы. App/Nginx имеют no-new-privileges. Файловая система app и
bind mount checkout теперь read-only; writable остаются storage и ограниченные
tmpfs /tmp (256 MiB, noexec) и bootstrap/cache (16 MiB). Bootstrap cache генерируется
root до старта FPM, затем для PHP workers доступен только на чтение.
Backend network пока общая; UFW/Fail2Ban не менялись. Изолированное изменение
SSH keys-only описано ниже.

## Read-only развёртывание

Отдельный `docker/readonly-entrypoint.sh` не публикует assets, не меняет public/storage,
не создаёт bucket и не очищает общий Blade cache. Перед переключением должны быть
готовы зависимости, public/build/manifest.json, assets Filament/Livewire и абсолютная
ссылка public/storage -> /var/www/storage/app/public. Подготовка выполняется на
хосте/в отдельном build-процессе с проверенными правами, не на обслуживающем runtime.

После обновления кода/настроек пересоздать app с полным набором overlays и проверить
Nginx upstream DNS (nginx -t, затем nginx -s reload при необходимости).
**Не запускать optimize:clear/config:clear/package:discover в serving-контейнере**:
это удалит подготовленные PHP-кеши, которые workers не могут пересоздать.
Не запускать vendor:publish в read-only runtime. Миграции — только через отдельный wrapper.

Проверки: `python3 scripts/verify-readonly-runtime.py --container arm-app
--url https://www.xn---15-7edz.xn--p1ai` на хосте. Они проверяют HTTP, БД/PDF,
нулевую вставку аналитики, очередь без обработки jobs, запрет записи кода и scanner.
Для нового экземпляра сначала запускать изолированного кандидата без внешних портов;
`scripts/deploy-readonly-runtime.py --apply` требует root, проверяет кандидата ещё раз,
сохраняет исходную конфигурацию и возвращает её при неуспехе production-проверок.
Не запускать повторно вслепую, если overlay уже выбран.

## SSH — 29 октября

После двух независимых проверок ключа применён keys-only drop-in:
PasswordAuthentication no, KbdInteractiveAuthentication no,
PermitRootLogin prohibit-password, PubkeyAuthentication yes.
После reload отдельный клиент подтвердил publickey как единственный предлагаемый
метод. Пятиминутный таймер отката отменён после успешного нового входа.
Существующие сеансы не прерывались, SSH-ключи не заменялись, локальный sudo сохранён.
Для дальнейших изменений есть `scripts/enable-ssh-keys-only.py`, но он намеренно
отказывается перезаписывать существующий drop-in. Не применять повторно без анализа.
UFW в этом этапе не менялся. Позже Fail2Ban включён отдельно, см. ниже.

## SSH Fail2Ban — вечер 29 октября

Активен только sshd jail: journald, 6 ошибок/10 минут, бан 10 минут.
Действие `ops/fail2ban/arm-sshd-new.conf` ограничивает бан начальным TCP SYN
и conntrack NEW на :22. Проверено сохранение существующего SSH-обмена IPv4/IPv6.
Не заменять обычным allports-действием: оно может оборвать административный доступ.
Текущее точечное исключение admin IP не следует за изменением адреса провайдера.
При случайном бане нового адреса уже открытый сеанс должен сохраняться;
автоматическое истечение бана — 10 минут. Поддерживать независимый console recovery.

`scripts/configure-fail2ban-ssh.py --apply --admin-ip <actual-client-ip>` запускается
root только для первоначального включения остановленной службы. Отказывается
перезаписывать уже установленные managed files или активную службу. Создаёт
приватный backup `/root/arm-fail2ban-<timestamp>`, rollback.py и timer на 5 минут;
при ошибке тестов останавливает Fail2Ban и удаляет только свои три файла.
После успеха отдельно проверить НОВЫЙ внешний SSH-вход и HTTP, затем отменить
именно timer из файла awaiting-client-confirmation. Не отменять его до проверки.
Не запускать начальный установщик повторно для обычной диагностики.

`sudo python3 scripts/verify-fail2ban-ssh.py` проверяет активный jail на отдельном
netns, блокирует только 192.0.2.123 и 2001:db8:ffff:1::123 и очищает свои ресурсы.
Опция `--baseline-only` выполняет SSH-обмен без Fail2Ban и без любых банов.
Тест требует свободных документационных подсетей и отказывается при конфликте.
Проверка использует keyscan, не выполняет пользовательский вход.

Почтовые/host-Nginx jails выключены намеренно до проверки логов.
Exim/Dovecot и старые PHP не менялись; исходящие SMTP-уведомления нужно сохранить.

## Отключение Roundcube PHP 7.4 — 29 октября, 21:28 МСК

В отдельном следующем этапе php-fpm74.service остановлен, disabled и masked.
Исходящие уведомления Laravel используют внешний SMTP:465; Exim/Dovecot оставлены.
TLS/certificate verification и SMTP greeting проверены из arm-app до и после
остановки. Это не проверка аутентификации или доставки писем.

Первоначальный установщик `sudo python3 scripts/disable-legacy-php.py --apply`
требует исходную active/enabled службу и остановленные host web-серверы;
не запускать повторно для диагностики. Backup и rollback.py:
`/root/arm-legacy-php-20261029T212758`. Финальный таймер отменён после внешнего SSH,
HTTP и проверки состояния контейнеров. Для аварийного восстановления Roundcube FPM
root может запустить этот rollback.py: он unmask/enable/start только php-fpm74.
Возврат старого runtime увеличивает поверхность атаки; затем нужен отдельный
переход Roundcube на поддерживаемый PHP. Webmail сейчас не включать через host Nginx:
он не обслуживает ARM, а его порты заняты Docker.

Файлы /opt/php74, пакеты и Roundcube сохранены. Mask защищает от обычного systemd
старта, но не от ручного запуска бинарника root или изменения настроек панелью.

## BIND private listeners — 29 октября, 21:42 МСК

Применён `scripts/restrict-bind-listeners.py --apply`: только loopback и текущие
IPv4 адреса Docker bridges, IPv6 ::1. Основной домен делегирован REG.RU, named
содержал лишь стандартные зоны; хост/контейнеры используют другой resolver.
Служба named остаётся active. Публичный :53 больше не прослушивается.
При изменении Docker bridge IP список listen-on требует отдельного пересмотра;
если в будущем нужны публичные зоны — сначала пересмотреть DNS-архитектуру.

Установщик не применять повторно: он откажется при managed marker или новых зонах.
Backup и rollback.py: `/root/arm-bind-listeners-20261029T214239`.
Откат копирует исходный named.conf.options и выполняет named-checkconf/rndc reconfig;
возвращает прежнее публичное прослушивание. Финальный timer отменён после проверок.
Подробности фактических портов/FTP/почты: SERVER_NETWORK_AUDIT_2026-10-29.md.

## FTP отключён — 30 октября, 18:26 МСК

По уточнению владельца FTP не используется. proftpd.service остановлен/disabled,
service/socket/template masked. SSH и его SFTP остаются доступны; новый вход обоими
способами проверен отдельно. На :21/:69/:990 и 35000–35999 listeners не найдены.
Не открывать эти порты в будущем firewall без новой потребности.

`scripts/disable-ftp.py --apply` — первоначальное применение для аудированного
active/enabled ProFTPD; отказывается при неожиданном состоянии. Не запускать повторно
для проверки. Backup /root/arm-ftp-20261030T182602 содержит конфигурацию и rollback.py,
который unmask всех трёх units и enable/start только прежней основной службы.
Откат возвращает FTP и увеличивает поверхность атаки; применять только при необходимости.
Пакеты/конфигурация не удалены, таймер отменён после SSH/SFTP/HTTP проверок.

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

## Проверка PostgreSQL backup — 30 октября

`sudo python3 scripts/verify-postgres-backup.py --run --existing-dump /absolute/file.dump`
создаёт свежий root-only дамп и восстанавливает оба источника в отдельном контейнере
без сети и host mounts, с ограниченными tmpfs/CPU/RAM. Нужны Docker и не менее 1 ГБ
свободного места; рассчитано на текущую маленькую БД, не на произвольные большие дампы.
Рабочая БД только читается pg_dump, рабочие тома в тест не подключаются.
Скрипт не поддерживает восстановление в production и не включает расписание.

Отчёт/дамп/приватные логи: /root/arm-backup-check-20261030T153858Z.
Старый и свежий дампы прошли реальное восстановление; owners/ACL исключены.
Тестовый контейнер удаляется в finally. При принудительном убийстве процесса/хоста
проверить `docker ps -a --filter name=arm-backup-check-` и убрать только тестовый
контейнер после проверки имени/изоляции; не трогать arm-postgres.

Это локальный тест PostgreSQL, не полный backup ARM и не внешняя копия.
Полный статус: BACKUP_AUDIT_2026-10-30.md; выбор хранилища: BACKUP_STORAGE_OPTIONS.md.
