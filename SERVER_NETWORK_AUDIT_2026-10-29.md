# Сетевая граница production — 29 октября 2026

## Проверенные факты

Проверены Compose, docker ps, live iptables/ip6tables (filter/nat/raw), listeners,
BIND configuration, DNS delegation, resolvectl и resolv.conf контейнеров.

| Объект | Состояние и ограничение доказательств |
|---|---|
| Docker | Только arm-nginx публикует 80/443 IPv4/IPv6. PostgreSQL, Redis, MinIO, ClickHouse, Meilisearch и FPM не имеют host port bindings |
| Docker ingress | DOCKER содержит ACCEPT только 80/443 Nginx и DROP остального входящего на bridges; raw ограничивает прямой вход к адресам контейнеров. DOCKER-USER пустая |
| Host firewall | INPUT policy ACCEPT в обеих семьях. UFW не активирован. Наличие цепочек UFW не означает действующую default-deny защиту |
| SSH | :22, keys-only + проверенный SYN/NEW Fail2Ban; правила сохранены |
| ISPmanager | :1500 IPv4, перед ISP-цепочками стоят IP allowlist и DROP остальных; эти правила не менялись. В ispmgr_allow_sub есть общий ACCEPT :1501, но listener не найден |
| IPv6 | На ens3 только link-local адрес; глобального IPv6 сейчас не найдено. Это не повод игнорировать IPv6-правила при будущей настройке адреса |
| FTP | proftpd :21, passive range 35000–35999. Владелец подтвердил использование обычного FTP без шифрования; службу и режим сохранить |
| FTPS config | TLSEngine on; TLSRequired закомментирован, TLSProtocol перечисляет TLSv1/1.1/1.2. Фактическая поддержка старых TLS библиотекой не проверена; TLS не обязателен |
| Почта | Exim :25/465/587, Dovecot :110/143/993/995. В очереди Exim 2 сообщения; содержимое/получатели не читались, почта не отключалась |
| Основной домен | NS ns1.reg.ru/ns2.reg.ru, SOA REG.RU. MX/AAAA в выполненном публичном запросе не получены; отсутствие MX не доказывает отсутствие локальной почты |
| MySQL | Host listeners только 127.0.0.1:3306/33060; это отдельный host MySQL, не PostgreSQL ARM |

Внешний TCP connect из доступной среды сообщил open также для портов без listeners
и host bindings. Результат недостоверен как доказательство доступности конечной
службы; ранее отмеченное ограничение среды воспроизвелось. Нельзя называть БД
публичными или firewall эффективным только по этому тесту. Полного внешнего
IPv4/IPv6 сканирования из независимой точки не выполнено.

## Применено: BIND на внутренних адресах

- В named-checkconf -p только стандартные зоны: ., localhost и reverse loopback.
- Хост использует systemd-resolved 127.0.0.53 с upstream провайдера; приложение
  и Nginx — Docker 127.0.0.11 с forwarding к 127.0.0.53, не к named.
- В /etc/bind/named.conf.options listen-on ограничен 127.0.0.1 и адресами текущих
  Docker-мостов 172.17.0.1/172.18.0.1/172.19.0.1, listen-on-v6 — ::1.
- named остаётся active. На публичном адресе TCP/UDP :53 listener отсутствует;
  DNS-запрос к 83.220.173.171:53 с хоста получил connection refused.
- Проверены синтаксис, фактические TCP/UDP listeners, SOA localhost через loopback,
  публичное имя и postgres из arm-app, внешний SMTP TLS до и после (без отправки
  и AUTH), отдельный SSH-вход, /login и /install 200, состояние контейнеров.
- Unit arm-bind-listeners-cutover-20261029: Result=success, ExecMainStatus=0.
  Backup /root/arm-bind-listeners-20261029T214239, rollback.py, таймер 5 минут.
  Таймер отменён после независимых проверок. Firewall/Docker правила не менялись.

## Осталось по этапам

1. Перед обязательным FTPS подготовить профиль клиента владельца и проверить
   доверие сертификату, TLS, login и passive transfer. Пока не запрещать обычный
   FTP: владелец использует его. Реальный риск — передача учётных данных без TLS.
2. Выяснить назначение локальных почтовых доменов/очереди, зависимость панели и
   системных уведомлений. Внешний SMTP Laravel не доказывает ненужность Exim/Dovecot.
3. Подготовить отдельный firewall change с default-deny, не включая UFW вслепую:
   сохранить провайдерский allowlist, SSH/Fail2Ban, FTP и passive range, необходимые
   почтовые порты, Docker forwarding/egress, established traffic и ICMP/IPv6 ND.
   Применять только с точечным rollback и независимой проверкой нового доступа.
4. Проверить внешние backups и пробное восстановление; затем мониторинг с внешним
   хранением событий и аккуратными автоматическими реакциями. Приоритет — возможность
   восстановить данные. Не вводить автоматическое удаление/шифрование файлов при тревоге.
5. После текущего hardening — PERSONAL_DATA_ENCRYPTION_PLAN.md.

Продуктовая работа по периодам отсутствия/PDF остаётся на ранее записанном checkpoint;
изменений APK, расчётов, доступа к документам и событий ClickHouse здесь нет.
