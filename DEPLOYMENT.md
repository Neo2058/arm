# DEPLOYMENT.md

**Подробный план развёртывания приложения ТЧ-15 на VDS**  
**Ограничения и требования:**
- Ubuntu 24.04 LTS
- Docker Compose
- UFW
- Fail2Ban
- Nginx (в качестве reverse proxy)
- Let's Encrypt (через certbot)
- Cloudflare (DNS + Proxy)
- Только SSH по ключам (без пароля)
- Nightly backups
- Приватная Docker-сеть (минимальное количество открытых портов)

**Исключены:** Caddy, Traefik, Portainer, Watchtower, внешние менеджеры секретов и т.п. Только базовые инструменты Ubuntu + Docker.

---

## 1. Подготовка VDS-сервера (Ubuntu 24.04)

### 1.1 Первоначальная настройка системы

```bash
# Обновление
sudo apt update && sudo apt upgrade -y

# Создание отдельного пользователя (рекомендуется)
sudo adduser deploy
sudo usermod -aG sudo deploy

# Выход и вход под пользователем deploy
su - deploy
```

### 1.2 Настройка SSH только по ключам

```bash
# На своей машине
ssh-keygen -t ed25519 -C "deploy@your-server"
ssh-copy-id -i ~/.ssh/id_ed25519.pub deploy@IP_СЕРВЕРА

# На сервере (под пользователем deploy)
mkdir -p ~/.ssh
chmod 700 ~/.ssh
nano ~/.ssh/authorized_keys   # вставь свой публичный ключ
chmod 600 ~/.ssh/authorized_keys

# Отключаем вход по паролю и root
sudo nano /etc/ssh/sshd_config
```

В `/etc/ssh/sshd_config` измени:

```conf
PermitRootLogin no
PasswordAuthentication no
PubkeyAuthentication yes
PermitEmptyPasswords no
ChallengeResponseAuthentication no
UsePAM no
```

Перезапуск SSH:

```bash
sudo systemctl restart ssh
sudo systemctl restart sshd
```

**Проверь**, что можешь зайти только по ключу.

### 1.3 UFW (Firewall)

```bash
sudo apt install ufw -y

sudo ufw default deny incoming
sudo ufw default allow outgoing

# Разрешаем только Cloudflare + SSH
# Сначала добавь IP своего текущего подключения (на всякий случай)
sudo ufw allow from ТВОЙ_ТЕКУЩИЙ_IP to any port 22

# После настройки Cloudflare можно будет сузить правила
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp

sudo ufw enable
sudo ufw status verbose
```

### 1.4 Fail2Ban

```bash
sudo apt install fail2ban -y

sudo nano /etc/fail2ban/jail.local
```

Пример содержимого `jail.local`:

```ini
[DEFAULT]
bantime = 1h
findtime = 10m
maxretry = 5

[sshd]
enabled = true
port = ssh
filter = sshd
logpath = /var/log/auth.log
maxretry = 4
bantime = 24h

[nginx-http-auth]
enabled = true
filter = nginx-http-auth
port = http,https
logpath = /var/log/nginx/error.log

[nginx-botsearch]
enabled = true
filter = nginx-botsearch
port = http,https
logpath = /var/log/nginx/access.log
maxretry = 2
```

```bash
sudo systemctl enable fail2ban
sudo systemctl restart fail2ban
```

---

## 2. Установка Docker и Docker Compose

```bash
# Docker
curl -fsSL https://get.docker.com -o get-docker.sh
sudo sh get-docker.sh
sudo usermod -aG docker deploy

# Docker Compose plugin уже включён в современный Docker
sudo apt install docker-compose-plugin -y

# Перелогинься
exit
# Войди заново под deploy
```

Проверка:

```bash
docker --version
docker compose version
```

---

## 3. Развёртывание проекта

```bash
cd ~
git clone <репозиторий> arm
cd arm
```

### Создание `.env`

```bash
cp .env.example .env
nano .env
```

**Важные production значения:**

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://твой-домен.ru

DB_CONNECTION=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_DATABASE=main_db
DB_USERNAME=...
DB_PASSWORD=...   # сложный!

REDIS_HOST=redis

CLICKHOUSE_HOST=clickhouse
CLICKHOUSE_PORT=8123
CLICKHOUSE_USER=default
CLICKHOUSE_PASSWORD=
CLICKHOUSE_DATABASE=default

# MinIO (если используется)
FILESYSTEM_DISK=s3
AWS_ACCESS_KEY_ID=...
AWS_SECRET_ACCESS_KEY=...
AWS_ENDPOINT=http://minio:9000          # внутри сети
AWS_USE_PATH_STYLE_ENDPOINT=true
AWS_BUCKET=documents
```

---

## 4. Docker Compose с приватной сетью

Создай или отредактируй `docker-compose.yml` (или `docker-compose.prod.yml`).

**Ключевые принципы:**
- Только `nginx` публикует порты 80 и 443.
- Все остальные сервисы находятся в **приватной сети**.
- Используем `networks` с `internal: true` или обычную bridge без публикации портов.

Пример структуры (упрощённо):

```yaml
services:
  nginx:
    image: nginx:stable-alpine
    ports:
      - "80:80"
      - "443:443"
    volumes:
      - .:/var/www
      - ./docker/nginx/default.conf:/etc/nginx/conf.d/default.conf:ro
      - /etc/letsencrypt:/etc/letsencrypt:ro
    networks:
      - frontend
      - backend
    depends_on:
      - app

  app:
    build: .
    restart: unless-stopped
    env_file: .env
    volumes:
      - .:/var/www
    networks:
      - backend
    depends_on:
      - postgres
      - redis
      - clickhouse

  postgres:
    image: postgres:15-alpine
    restart: unless-stopped
    env_file: .env
    volumes:
      - postgres_data:/var/lib/postgresql/data
    networks:
      - backend

  clickhouse:
    image: clickhouse/clickhouse-server:latest
    restart: unless-stopped
    volumes:
      - clickhouse_data:/var/lib/clickhouse
    networks:
      - backend

  redis:
    image: redis:alpine
    restart: unless-stopped
    networks:
      - backend

  minio:
    image: minio/minio:latest
    restart: unless-stopped
    command: server /data --console-address ":9001"
    volumes:
      - minio_data:/data
    networks:
      - backend
    # Порты MinIO НЕ открываем наружу

networks:
  frontend:
    driver: bridge
  backend:
    driver: bridge
    internal: true          # <-- приватная сеть (рекомендуется)

volumes:
  postgres_data:
  clickhouse_data:
  minio_data:
```

**Важно:** `internal: true` у `backend` означает, что эта сеть не имеет выхода в интернет (кроме как через nginx, если нужно).

---

## 5. Настройка Nginx + Let's Encrypt + Cloudflare

### 5.1 Конфигурация Nginx

`docker/nginx/default.conf`:

```nginx
server {
    listen 80;
    server_name твой-домен.ru www.твой-домен.ru;
    return 301 https://$server_name$request_uri;
}

server {
    listen 443 ssl;
    http2 on;
    server_name твой-домен.ru www.твой-домен.ru;

    root /var/www/public;
    index index.php;

    # SSL будет добавлен certbot

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass app:9000;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }
}
```

### 5.2 Получение сертификата Let's Encrypt

Сначала запусти nginx с временной конфигурацией на 80 порт, затем:

```bash
docker compose run --rm app certbot certonly --webroot \
  -w /var/www/public \
  -d твой-домен.ru \
  --email твоя@почта.ru \
  --agree-tos --non-interactive
```

Или используй плагин:

```bash
sudo apt install certbot python3-certbot-nginx -y
```

После получения сертификатов обнови `default.conf` и добавь пути к сертификатам.

**Важно без Cloudflare:**
- Убедись, что UFW разрешает 80 и 443:
  ```bash
  sudo ufw allow 80/tcp
  sudo ufw allow 443/tcp
  sudo ufw reload
  sudo ufw status
  ```
- Если у провайдера есть свой Firewall в панели (Hetzner, DO, Linode и т.д.) — обязательно открой 80 и 443 там тоже.
- Порт 443 должен быть доступен снаружи.

---

## 6. Запуск и инициализация

```bash
docker compose up -d --build

# Зайти в контейнер приложения
docker compose exec app bash

# Внутри:
php artisan key:generate --force
php artisan migrate --force
php artisan storage:link
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan optimize
```

### Инициализация ClickHouse таблицы

```bash
docker compose exec clickhouse clickhouse-client --query "
CREATE TABLE IF NOT EXISTS default.user_actions (
    event_date Date,
    event_time DateTime,
    user_id UInt32,
    user_role String,
    user_column String,
    action_type String,
    resource_id UInt32,
    details String
) ENGINE = MergeTree()
ORDER BY (event_date, event_time);
"
```

---

## 7. Фоновые процессы

Добавь в `docker-compose.yml`:

```yaml
  queue:
    build: .
    restart: unless-stopped
    command: php artisan queue:work --sleep=3 --tries=3 --max-time=3600
    env_file: .env
    networks:
      - backend

  scheduler:
    build: .
    restart: unless-stopped
    command: >
      sh -c "while true; do
        php artisan schedule:run --verbose --no-interaction
        sleep 60
      done"
    env_file: .env
    networks:
      - backend
```

---

## 8. Nightly Backups (автоматические бэкапы)

Создай скрипт `scripts/backup.sh`:

```bash
#!/bin/bash
DATE=$(date +%Y%m%d_%H%M)
BACKUP_DIR=/home/deploy/backups
mkdir -p $BACKUP_DIR

# PostgreSQL
docker compose exec -T postgres pg_dump -U $DB_USER $DB_NAME > $BACKUP_DIR/db_$DATE.sql

# ClickHouse
docker compose exec clickhouse clickhouse-client --query "BACKUP DATABASE default TO Disk('default', 'backup_$DATE')"

# MinIO (пример)
docker compose exec minio mc alias set local http://minio:9000 $MINIO_USER $MINIO_PASS
docker compose exec minio mc mirror --overwrite local/documents $BACKUP_DIR/minio_$DATE/

# Очистка старых бэкапов (оставляем 7 дней)
find $BACKUP_DIR -type f -mtime +7 -delete
```

Добавь в crontab пользователя `deploy`:

```bash
crontab -e
```

```
0 3 * * * /home/deploy/arm/scripts/backup.sh >> /home/deploy/backups/backup.log 2>&1
```

---

## 9. Итоговый чек-лист безопасности

- [ ] SSH только по ключам + `PasswordAuthentication no`
- [ ] UFW включён (80, 443, 22)
- [ ] Fail2Ban настроен
- [ ] Только Nginx публикует порты наружу
- [ ] Все остальные сервисы в `internal` или приватной сети
- [ ] Cloudflare Proxy включён
- [ ] Let's Encrypt сертификат получен и обновляется (`certbot renew` через cron)
- [ ] `.env` не в репозитории
- [ ] Ночные бэкапы работают и проверены
- [ ] `APP_DEBUG=false`
- [ ] `php artisan optimize`

---

## Полезные команды

```bash
# Логи
docker compose logs -f nginx app

# Перезапуск
docker compose restart nginx

# Обновление сертификатов
certbot renew --quiet

# Ручной бэкап
bash scripts/backup.sh
```

---

**Рекомендация:** После первого успешного деплоя сохрани этот файл с реальными командами и путями, которые получились у тебя на сервере.

Если нужно — могу дополнительно подготовить:
- Пример `docker-compose.prod.yml`
- Готовый скрипт бэкапа
- Пример конфига Nginx под Cloudflare + Let's Encrypt

Готов помочь доработать любой пункт.