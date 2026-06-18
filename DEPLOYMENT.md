# DEPLOYMENT.md

Подробный план развёртывания приложения **ТЧ-15** в Docker на VDS-сервере.

Цель — ничего не забыть и получить рабочую production-среду.

---

## 1. Подготовка VDS-сервера

### Рекомендуемые характеристики
- **CPU**: 2–4 ядра
- **RAM**: минимум 4 ГБ (лучше 8 ГБ, если будет ClickHouse + MinIO)
- **Диск**: от 40–60 ГБ SSD
- **ОС**: Ubuntu 22.04 LTS или 24.04 LTS (рекомендуется)

### Начальная настройка сервера

```bash
# Обновление системы
sudo apt update && sudo apt upgrade -y

# Создай пользователя (рекомендуется)
sudo adduser deploy
sudo usermod -aG sudo deploy

# Настрой SSH (скопируй свой ключ)
# После этого можешь отключить парольный вход

# Firewall
sudo apt install ufw -y
sudo ufw default deny incoming
sudo ufw default allow outgoing
sudo ufw allow 22/tcp
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw enable

# Установка Docker
curl -fsSL https://get.docker.com -o get-docker.sh
sudo sh get-docker.sh
sudo usermod -aG docker deploy

# Docker Compose (v2)
sudo apt install docker-compose-plugin -y

# Перелогинься под пользователем deploy
```

---

## 2. Подготовка проекта на сервере

```bash
cd ~
git clone <твой-репозиторий> arm
cd arm
```

### Создание production `.env`

```bash
cp .env.example .env
nano .env
```

**Обязательные переменные для production:**

```env
APP_NAME="ТЧ-15"
APP_ENV=production
APP_KEY=base64:СГЕНЕРИРУЙ_КОМАНДОЙ_НИЖЕ
APP_DEBUG=false
APP_URL=https://твой-домен.ru

# База
DB_CONNECTION=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_DATABASE=main_db
DB_USERNAME=...
DB_PASSWORD=...   # Сложный пароль!

# Redis
REDIS_HOST=redis

# ClickHouse (критично для Журнала ТЧМ)
CLICKHOUSE_HOST=clickhouse
CLICKHOUSE_PORT=8123
CLICKHOUSE_USER=default
CLICKHOUSE_PASSWORD=   # можно оставить пустым или задать
CLICKHOUSE_DATABASE=default

# Telegram (уведомления)
TELEGRAM_BOT_TOKEN=...
TELEGRAM_CHAT_ID=...
TELEGRAM_GROUP_ID=...

# MinIO (хранение документов)
FILESYSTEM_DISK=s3
AWS_ACCESS_KEY_ID=minio_admin
AWS_SECRET_ACCESS_KEY=...
AWS_ENDPOINT=https://твой-домен.ru:9000   # или внутренний
AWS_USE_PATH_STYLE_ENDPOINT=true
AWS_BUCKET=documents

# Другие сервисы
MEILISEARCH_HOST=http://meilisearch:7700
MEILISEARCH_KEY=...

# Почта (рекомендуется SMTP)
MAIL_MAILER=smtp
...
```

**Генерация APP_KEY:**
```bash
docker compose run --rm app php artisan key:generate --show
```

Скопируй значение в `.env`.

---

## 3. Docker Compose для Production

Рекомендуется создать `docker-compose.prod.yml` (или использовать override).

**Важные улучшения для прода:**

- Не используй hardcoded пароли
- Ограничь порты (MinIO, ClickHouse, Meilisearch не должны быть открыты наружу)
- Используй named volumes для данных
- Добавь restart: always
- Настрой логи

Пример структуры (создай файл `docker-compose.prod.yml`):

```yaml
services:
  app:
    build: .
    restart: always
    env_file: .env
    volumes:
      - app_storage:/var/www/storage
    depends_on:
      - postgres
      - redis
      - clickhouse

  nginx:
    image: nginx:stable-alpine
    restart: always
    ports:
      - "80:80"
      - "443:443"
    volumes:
      - .:/var/www
      - ./docker/nginx/default.conf:/etc/nginx/conf.d/default.conf:ro
      - ./docker/nginx/ssl:/etc/nginx/ssl:ro   # для сертификатов
    depends_on:
      - app

  # ... остальные сервисы с restart: always и volumes
```

Запуск в production:
```bash
docker compose -f docker-compose.yaml -f docker-compose.prod.yml up -d --build
```

---

## 4. Инициализация приложения

Выполняй команды внутри контейнера `app`:

```bash
# Зайди в контейнер
docker compose exec app bash

# Внутри контейнера:
php artisan migrate --force
php artisan storage:link
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan optimize

# Если нужны сиды
# php artisan db:seed --force
```

### Инициализация ClickHouse

Журнал ТЧМ использует таблицу `user_actions`.

Создай таблицу (выполни один раз):

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

## 5. Фоновые процессы (критично)

### Queue Worker

В production нужно постоянно работать с очередями (задачи, логи и т.д.).

Варианты:

**A. Через docker-compose (рекомендуется)**

Добавь сервис:

```yaml
queue:
  build: .
  restart: always
  command: php artisan queue:work --sleep=3 --tries=3 --max-time=3600
  env_file: .env
  depends_on:
    - app
    - redis
```

**B. Supervisor** (внутри контейнера app)

### Scheduler (планировщик)

Добавь в `docker-compose`:

```yaml
scheduler:
  build: .
  restart: always
  command: >
    sh -c "while true; do
      php artisan schedule:run --verbose --no-interaction &
      sleep 60
    done"
```

Или используй cron внутри контейнера.

---

## 6. SSL и Reverse Proxy (самое важное)

### Вариант 1 — Caddy (самый простой и рекомендуемый)

1. Установи Caddy отдельно или используй образ.
2. Простая конфигурация с автоматическим Let's Encrypt.

Пример `Caddyfile`:

```
твой-домен.ru {
    reverse_proxy app:9000   # или nginx
}

minio.твой-домен.ru {
    reverse_proxy minio:9000
}
```

### Вариант 2 — Nginx + Certbot

- Поставь certbot на хост
- Получи сертификаты
- Проксируй трафик на порт 8080 (или измени nginx)

**Никогда не открывай порт 8080 наружу в production** — поменяй на внутренний.

---

## 7. Важные настройки после деплоя

```bash
# Очистка кэша
php artisan optimize:clear

# Проверка очередей
php artisan queue:work --once

# Проверка расписания
php artisan schedule:list
```

### Настройка Telegram webhook (если используется)

```bash
php artisan telegram:webhook:set
```

---

## 8. Резервное копирование (обязательно!)

### Минимум:
- PostgreSQL дампы
- ClickHouse бэкапы
- MinIO бакеты (`mc mirror` или `rclone`)
- `.env` и docker volumes

Пример скрипта бэкапа (сохрани в `/opt/backups/`):

```bash
#!/bin/bash
DATE=$(date +%Y%m%d_%H%M)
docker compose exec -T postgres pg_dump -U neo main_db > /backups/db_$DATE.sql
# Аналогично для ClickHouse и MinIO
```

Запускай через cron.

---

## 9. Мониторинг и логи

- `docker compose logs -f app`
- `docker stats`
- Настрой `logrotate` для логов контейнеров
- Рассмотри Portainer или Dockge для удобного управления

---

## 10. Чек-лист перед запуском в продакшен

- [ ] `APP_ENV=production` и `APP_DEBUG=false`
- [ ] `APP_KEY` сгенерирован и задан
- [ ] Все пароли в `.env` изменены (не дефолтные)
- [ ] Миграции выполнены
- [ ] ClickHouse таблица `user_actions` создана
- [ ] `php artisan storage:link`
- [ ] Кэш и оптимизация выполнены
- [ ] Queue worker работает
- [ ] Scheduler работает
- [ ] SSL сертификат получен и работает
- [ ] Firewall открыт только 80/443 (и 22)
- [ ] Бэкапы настроены и проверены
- [ ] Доступ к `/admin` ограничен (через Filament или дополнительный пароль)
- [ ] Тестовый инструктор может зайти в Журнал ТЧМ

---

## 11. Полезные команды

```bash
# Пересборка
docker compose up -d --build

# Смотреть логи
docker compose logs -f app nginx

# Зайти в контейнер приложения
docker compose exec app bash

# Выполнить artisan команду
docker compose exec app php artisan ...

# Остановить всё
docker compose down
```

---

## Дополнительные рекомендации

1. **Используй docker secrets** или внешний менеджер секретов (Doppler, Vault) для важных ключей.
2. Настрой автоматическое обновление системы и Docker.
3. Рассмотри использование `watchtower` или ручные обновления.
4. Для ClickHouse в продакшене рекомендуется задать пользователя и пароль.
5. MinIO лучше ставить за reverse proxy с отдельным поддоменом.

---

**После успешного деплоя** обнови этот файл с реальными командами и нюансами твоего сервера.

Удачи с развёртыванием! Если что-то пойдёт не так — возвращайся к этому плану.