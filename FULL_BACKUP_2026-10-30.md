# Первый зашифрованный recovery-набор ARM — 30 октября 2026

Статус: набор собран, загружен и принят на ПК 30 октября в 19:40 МСК.
Ciphertext hash, age authentication и все 60 файлов проверены по manifest.
Проверка .verified.json сохранена рядом с архивом, plaintext на диск ПК не записывался.
Расписание, ограниченный SSH-пользователь и автоматическое удаление не включены.

## Архив

- Сервер: /var/lib/arm-backups/arm-full-20261030T162223Z.tar.gz.age.
- Размер: 146 788 865 байт (около 140 МиБ).
- SHA-256: df2b481342801b587a1b95cd1da74869fa18d4e9fc325e88432751bc471f69ff.
- Формат: age-encrypted tar.gz, arm-recovery-set-v1.
- Исходный commit: e33048e9931f003e4946616c70cd192e978e53ec.
- ПК: ~/.tch15-arm/backups/full/arm-full-20261030T162223Z.tar.gz.age,
  mode 0600, каталог 0700. Внутри 60 компонентов, суммарно 191 684 667 plaintext bytes.
- Сборка 30 октября 19:22:23–19:22:54 МСК, unit
  arm-full-backup-manual-20261030-retry1 Result=success/ExecMainStatus=0.

Закрытый age-ключ только на Mac:
`/Users/vyacheslavandreevich/.tch15-arm/backup-keys/full-backup.agekey` (0600,
каталог 0700). Серверу передан только recipient: /etc/arm/backup-recipient.
Это отдельный ключ, прежний recovery.key для маленького Fernet dump сохраняется.
Значения ключей и plaintext-секреты не выводить в чат/логи/Git.

## Состав

- PostgreSQL custom dump + роли, включая необходимые для восстановления секреты.
  Точный dump из набора успешно восстановлен в отдельном PostgreSQL без сети,
  bind mounts и production volumes. Отчёт включён в архив, hash dump сверяется при приёмке.
- Все 45 текущих объектов MinIO: содержимое, metadata, tags, ключи в manifest,
  конфигурация бакетов (ACL, versioning, policy и доступные дополнительные настройки).
  Объектные ключи не используются как пути: файлы хранятся под SHA-256 именами.
  Экспорт текущего скрипта допускает только бакеты без versioning; при его включении
  откажется, чтобы не потерять старые версии молча.
- Пользовательский storage/app. В этом снимке файлов нет; framework cache/logs
  не включены как пользовательские данные.
- ClickHouse default.user_actions: CREATE TABLE и Native data, без system logs.
  Native-файл прочитан отдельным clickhouse-local контейнером без сети/host mounts.
  Схема/TTL/события production не менялись.
- Архив исходного кода commit, текущий public (включая installers/assets), tracked
  changes patch. Manifest/metadata image digests и Docker mounts/configuration.
- Private configuration: per-service env и .env, TLS, SSH, Fail2Ban, BIND, ProFTPD,
  Compose, cron/systemd и host firewall snapshot, версии установленных пакетов.
  Эти файлы содержат реальные секреты; экспортируется только шифротекст.

## Согласованность и проверки

До/после копирования сравниваются MinIO inventory с metadata/tags и SHA-256 файлов
storage. Смена инвентаря приводит к отказу; версия кода также проверяется повторно.
PostgreSQL pg_dump использует собственный согласованный snapshot. Для аналитики —
отдельный SELECT и повторная проверка схемы. Это не распределённая транзакция всех
сервисов; времена export записаны в manifest. Нет общей гарантии exactly-once между
изменениями PostgreSQL и отложенными analytics events.

Сборщик не останавливал приложение, не менял данные/доступ/схемы и не включал
maintenance. Временный staging root-only очищен после сборки. Единый итоговый tar.gz
на диск сервера не сохранялся: поток шифруется age. Компонентные tar, dump и
экспортированные объекты существовали в root-only staging; защита от root/RCE во
время работы backup этим не обещается. При принудительном убийстве скрипта проверить
остатки work-* вручную и не удалять готовые архивы.

Первый запуск отказался из-за Git dubious ownership (root job/deploy checkout),
staging удалён. Исправлено только конкретной Git option safe.directory для этого
пути в каждом вызове; глобальная конфигурация Git не менялась.

Приёмник на ПК проверяет SHA-256 ciphertext, полное завершение age authentication,
все файлы/размеры/hashes manifest и соответствие отчёта восстановления dump.
Ничего не распаковывает на диск; traversal, absolute paths, symlink/hardlink,
дубликаты и отсутствующие компоненты отклоняются. Незавершённая загрузка .partial
не считается копией; реализована докачка. Готовые копии не перезаписываются.

Тесты: `python3 -m unittest discover -s tests/Support -p test_backup_archive.py -v`,
10 проверок: корректный набор, неверный hash, пропущенный компонент, traversal,
дубликат, повреждённый ciphertext, absolute/foreign paths, последовательная докачка,
отказ при повреждённом префиксе и сборка параллельных диапазонов.

## Повторная ручная процедура

На сервере root (без передачи закрытого age-ключа):

```bash
sudo python3 scripts/build-full-backup.py --run --recipient-file /etc/arm/backup-recipient
```

Нужны age/python3-boto3 и 8 ГиБ свободного staging headroom. Существующие готовые
копии не удаляются. Одна сборка за раз через flock; временные контейнеры имеют
ограничения CPU/RAM. Root-скрипты пока лежат в deploy-owned repo: не выдавать
NOPASSWD для их произвольного выполнения и не ставить их так в unattended cron.
Для расписания потребуется reviewed root-owned installation.

На Mac в каталоге репозитория (sudo password запрашивается, не сохраняется):

```bash
python3 scripts/pull-full-backup.py \
  --source /var/lib/arm-backups/arm-full-20261030T162223Z.tar.gz.age \
  --sha256 df2b481342801b587a1b95cd1da74869fa18d4e9fc325e88432751bc471f69ff \
  --bytes 146788865 --streams 4
```

Каталог назначения ~/.tch15-arm/backups/full (0700), ciphertext и receipt 0600.
При обрыве повторить ту же команду: private .partial будет дозагружен, затем весь
файл проверен целиком. По умолчанию допустимое время передачи — 1 час.
`--streams 4` загружает остаток четырьмя диапазонами; временные части зашифрованы,
объединяются только после завершения чтения, затем проверяется полный hash/age/
manifest. При неуспехе параллельные временные диапазоны удаляются, исходный префикс
.partial сохраняется. Один transfer за раз через локальный flock.
SSH agent/X11/port forwarding, LocalCommand и повторное использование чужой
ControlPath явно отключены для передачи backup.
SHA-256 и размер для нового архива брать из его root-owned receipt, не из примера.

## Что ещё нужно для восстановления всего приложения

Набор не является образом ОС и не содержит OCI image binaries или установленных
vendor/node_modules. Для восстановления нужны проверенные registry/package
repositories и зависимости из lock-файлов. Redis cache/sessions и перестраиваемый
Meilisearch index не сохраняются. Политика техлогов ClickHouse остаётся месячной.

MinIO IAM/service account export отдельным admin API в этой итерации не выполнен:
нужно создать bucket-scoped runtime account заново и обновить private app env,
сохранив принцип «runtime без root S3 key». Бакетные настройки и объекты есть в наборе.
PostgreSQL роли/ACL требуют отдельного контролируемого восстановления; тест dump
был без owners/ACL. Не исполнять роли/host config и не распаковывать архив прямо в /.

Следующая приёмка — отдельное чистое окружение: импорт БД/объектов/Native, новые
runtime credentials с минимальными правами, app startup, вход, PDF и прикладная
аналитика. Полный app restore пока не проверен. После ручной приёмки — отдельный
ограниченный pull-key/пользователь, root-owned exporter и расписание на Mac.
