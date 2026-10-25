# AGENTS.md — Правила работы с проектом ARM (для Grok, Claude и других AI-ассистентов)

## О проекте

**ARM** — внутреннее веб-приложение для автоматизации рабочих процессов (предположительно локомотивное депо / железнодорожная инфраструктура).

Ключевые домены:
- Учёт и расчёт рабочих смен машинистов (`WorkShift`)
- Система обучения и проверки знаний через квизы (`Quiz`, `Question`, `QuizResult`)
- Управление инструкциями и документами (`Document`)
- Каталоги маршрутов и отклонений (`RoutesCatalog`, `DeviationsCatalog`)
- Регистрация устройств и контроль доступа (barrier, device registration)
- Аналитика действий пользователей (ClickHouse)
- Отчёты об ошибках и интеграция с Telegram

## Технологический стек

**Backend:**
- Laravel 12
- Filament 3.2 (основной инструмент админки)
- PHP 8.2.35+ (`php:8.2.35-fpm`; `docker/php/zz-php2shell.ini` выключает `session.upload_progress` — PHP2Shell CVE-2026-109618/109619)
- Sanctum / сессии (кастомный `AuthController`)

**Базы данных (критично!):**
- **PostgreSQL** — основная операционная БД (пользователи, смены, квизы, документы)
- **ClickHouse** — аналитика и логи действий пользователей (`user_actions`)
- Redis — кеш и сессии (не очереди)
- MinIO — S3-хранилище документов
- Meilisearch — полнотекстовый поиск

**Frontend:**
- Гибридный подход: Blade + **React 19 islands** (Vite)
- React-компоненты монтируются точечно на страницах (не полноценный SPA)
- Используемые библиотеки: framer-motion, GSAP, lucide-react, axios

**Инфраструктура:**
- Docker Compose (все сервисы в одной сети `arm`)
- Nginx + PHP-FPM в контейнере

## Архитектура

### Основные принципы
1. **Filament-first** для административных CRUD и сложных форм (особенно конструктор квизов).
2. **React islands** только для сложной интерактивности на стороне пользователя (проигрыватель квизов, календарь смен, просмотр документов, таймеры).
3. **Разделение ответственности БД**:
   - Всё операционное — в Postgres.
   - Логи действий, статистика, аналитика — строго в ClickHouse через `ClickHouseService`.
4. **Precomputed данные** в `WorkShift` (`total_minutes`, `estimated_earnings`) — расчёты происходят в `booted()` модели.
5. Гибридная авторизация: кастомный логин + Filament auth + роли через enum.

### Ключевые модели и связи
- `User` + `UserProfile` + `UserDevice` + роли (`UserRole` enum)
- `WorkShift` → `RoutesCatalog`, `DeviationsCatalog`, `User`
- `Quiz` → `Document` → `Question` → `Answer` + `QuestionReference` (ссылки на конкретные страницы PDF)
- `QuizResult` (с флагом `is_viewed`)
- `Document` (хранится в MinIO)

### Важные сервисы
- Telegram: `MonthlyAccessKey` — единый месячный ключ для барьера и бота. Не дублировать алгоритм.
- `TelegramAccountLinker` — одноразовая привязка активного пользователя к Telegram ID (Postgres); страница `/account/telegram` доступна после auth до device/barrier.
- Мобильный профиль: `/api/mobile/account/telegram` (GET/POST/DELETE), Sanctum + active user;
  POST выдаёт личную ссылку через тот же TelegramAccountLinker, без web-сессии, no-store и throttle.
- `TelegramPasswordReset` — `/reset` только в личном чате ранее привязанного активного пользователя.
  Хеш одноразового токена в Postgres, срок 10 минут, выпуск не чаще раза в минуту; ссылка содержит fragment,
  новый пароль вводится на `/password/reset` (CSRF). `APP_URL` должен быть HTTPS. При сбросе удаляются
  Sanctum-токены, обновляется remember token; `AuthenticateSession` в web проверяет отпечаток пароля.
- Бот ключей использует отдельные `TELEGRAM_KEY_*`, webhook `/telegram/webhook` защищён secret_token, выдаёт `/key` только привязанному активному пользователю в личке. Групповая публикация отключена.
- `TelegramService` — административные уведомления отдельным ботом, текстовые jobs `SendTelegramNotification` в database/notifications (нужен worker). Инструкция/relay/команды — `TELEGRAM_SETUP.md`.
- `ClickHouseService::log($actionType, $resourceId, $details, $actor = null)` — фиксация действия в ClickHouse (очередь `analytics`). `$actor` — `User`, id или null (тогда `Auth::user()`, иначе `user_id=0` / `user_role=system`).
- `ActionLog::log()` — операционный журнал в Postgres (Filament) **и** одна запись в ClickHouse. Не вызывай `ClickHouseService::log()` следом за тем же событием.
- Клиентский `POST /api/actions/log` — только белый список `ActionLog::CLIENT_ACTIONS`, в БД уходит как `ui.{action}`. Серверные мутации сюда не писать.
- `FileProxyService` — стрим документов и training-медиа из MinIO через приложение. Не возвращай `Storage::temporaryUrl()` в браузер для новых фич. Не меняй Filament `FileUpload` (disk / visibility / `getUploadedFileUsing`) мимоходом.
- `NaryadHoursCalculator` — часы сетки наряда; формулы не дублировать в контроллерах.
- `TelegramService`
- Кастомные контроллеры в `App\Http\Controllers\Api\...`

### Маршруты
`routes/web.php` — публичные + группа `auth` + Check-middleware. Домены:

- `routes/account.php`, `documents.php`, `training.php`, `work.php`, `journal.php`, `naryad.php`, `nsi.php`, `uchet.php`, `support.php`

Новые URI клади в соответствующий файл. Имена маршрутов не ломай (на них завязан JS).

### Мобильный API (`/api/mobile`)
- Sanctum personal access token (`Authorization: Bearer`).
- JSON для логина, документов, квизов, нарядов, телефонов, тем обучения, материалов, комментариев.
- Стрим файлов: signed URL (`mobile.files.documents` / `mobile.files.training` / `mobile.files.naryads`) + `FileProxyService`, без сессии и без MinIO URL. Клиент не открывает PDF URL в WebView (это включает DownloadManager); байты читаются в приложении и рисуются PDF.js. Попытки screenshot/recording/download/share/print — `POST .../documents/{id}/violation`.
- Квизы: `QuizService::playerData` / `grade` (без `is_correct` на клиенте). Наряды: личный TXT + общий PDF.
- Телефоны: общая таблица `phone_contacts`, GET/POST `/api/mobile/phones` и session/CSRF `/api/phones`.
  `PhoneDirectory` нормализует номера, уникальный индекс БД запрещает дубли. Создание доступно активным
  пользователям, throttle 10/min. Согласованный аудит `phone.created`: только ID контакта и автора.
  Перенос `arm_personnel` — `phones:import-personnel` (аудит), затем `--apply`, без изменения кадровых данных.
  Импортёр FoxPro не перезаписывает общий справочник: перенос телефонов запускается явно после импорта.
  Прежние `naryad_phones` браузера переносятся только вручную, без автоматической публикации примеров.
- Клиент: каталог `mobile/` (Expo). Не путать с web-группой (CSRF / device / barrier).
- Привязка устройства на мобильном прототипе **не** включена.
- Установщики (не браузер): `eas.json` профиль `preview` → APK/IPA с вшитым `EXPO_PUBLIC_API_URL`. Артефакты класть в `public/downloads/tch15-android.apk` и `tch15-ios.ipa`, страница `/install`.

### Клон АРМ-ЛБ
Схемы таблиц и шаги переноса FoxPro — **`armd.md`**. Не заводить параллельные таблицы учёта в обход этой схемы.
Что сделано и что делать дальше — **`armd-progress.md`**. Не начинать следующий шаг очереди, пока не закрыт текущий.

### Планирование наряда
Контроллеры в `App\Http\Controllers\Naryad\`:
- `PlanningController` — оболочка `/naryad`
- `SetkaController` — сетка, assign/unassign, лимиты подстроек
- `CatalogsController` — справочники
- `BreakdownsController` — разбивки смен (`RAZBSM`) и праздники (`PRAZD`)
- `PrintController` — печать нарядов и выписка в комнату отдыха
- `App\Services\Arm\ShiftHoursService` — часы назначения из разбивки
- `App\Services\Arm\PlanirRulesService` — контроли сетки PLANIR (assign блокирует нарушения; правила можно ужесточать, облегчать нельзя)
- `App\Services\Arm\PlanirControlService` — отчёт полноты сетки (F6 / Shift+F5); `/naryad/partial/control`
- `App\Services\Arm\NaryadPrintService` — печать нарядов (DOKMENU pecnar); `/naryad/partial/print`, `/naryad/print`
- `App\Services\Arm\PersonnelImporter` — картотека `LKM`, назначения `NAZN`, периоды `OTVM`, подстройки `POZEL`
- `PersonnelController` — картотека / назначения / отвлечения
- `App\Services\Arm\FormulaInterpreter` / `LsTotalsCalculator` / `AccountBuilder` / `UnderworkAllocator` / `UkCopyService` / `DokmenuReportService` / `LsbuhExportService` / `DbfWriter` — ЛС, LENA, копии УК, справки DOKMENU, `LSBUH.DBF`
- `App\Http\Controllers\Nsi\NsiController` — НСИ `/nsi` (KODIF/ELKODIF, RASST, PODPISI, NRCHAS/PREM/VISL/тарифы, NRDPAR)
- роль `operator`, рабочее место `/uchet` (карточки, ЛС первичные/повторные, доплаты `/uchet/extras`, МЛ `/uchet/sheets`, архив УК `/uchet/archive`, отчёты `/uchet/reports`, `LSBUH.DBF` `/uchet/lsbuh.dbf`, CSV, закрытие месяца)
- импорт DBF: `php artisan arm:import-dbf {path}` (`--only=…,formulas,chas2,naryad,nrchas,nrdpar,prem,visl,extras,spprem,tariffs,codes,signatures,rosters`; `--month=YYYY-MM` и `--tabs=` для naryad/chas2)
- выгрузка бухгалтерии: `php artisan arm:export-lsbuh YYYY-MM [path]` (`--kind=primary|repeat`)
- эталонный месяц: `php artisan arm:golden-month 2018-11 --tabs=0321,0428,2575` (`GoldenMonthComparer`)

Доступ: `EnsureDispatcher` (роль `dispatcher`).

### Фронтенд-монтирование
Смотри `resources/js/app.jsx`. Компоненты монтируются по `id`:
- `quiz-player`
- `general-quiz-player`
- `work-calendar-root`
- `document-viewer`
- `naryad-viewer`
- `count-down-timer`
- `carousel-menu`

Данные обычно передаются через `window.__QUIZ_DATA__`, `window.__GENERAL_QUIZ_DATA__`, `window.__DOCUMENTS_TREE__` и data-атрибуты.

Просмотр выданных нарядов (`/naryady`) — React-остров `NaryadViewer`. Пользователь видит свой файл (имя = ФИО) и общий PDF без отбора. Сборку файлов делает админ (`NaryadIssuePublisher`, ядро как в C++ `searching`). Поиск ФИО для инструктора — `/journal/naryad-search`. PDF общего наряда — `GET /naryady/{id}/pdf` через `FileProxyService`.

Стек фронта — JSX-острова без TypeScript. Не начинать массовый JS→TS до закрытия долга в `TECHNICAL_DEBT.md`: долг там PHP (CSRF, middleware, MinIO, Filament, два расчёта зарплаты).

## Правила разработки (обязательно соблюдать)

### Общие
- **Всегда читай** релевантные файлы (модели, ресурсы Filament, контроллеры, миграции) перед тем, как предлагать изменения.
- Для задач с неоднозначностью или большим объёмом изменений — сначала предлагай план (`/plan` или ручной план) и жди подтверждения.
- Используй `todo_write` для любых задач из 3+ шагов.
- Не трогай `vendor/`, `node_modules/`, сгенерированные билды без крайней необходимости.
- Все секреты — только через `.env` + `config/`. Никогда не хардкодь пароли/ключи.
- После правок доступа, файлов или маршрутов гоняй существующие feature-тесты (`JournalAccessTest`, `FileProxyTest`, `NaryadPlanningTest`, `WebRoutesSplitTest`, `UserRoleAccessTest`).

### Работа с квизами и документами
- `QuizService` проверяет доступ при открытии и отправке, формирует данные игрока без `is_correct` и считает балл на сервере.
- `/api/quiz-results` принимает `answers_log` (question ID → answer ID); клиентские `score`/`total_questions` игнорируются.
- При правках квизов backend и frontend build выкладывать вместе; порядок — `SECURITY_ROADMAP.md`.
- При изменении структуры квиза/вопросов/ответов/ссылок на инструкции обязательно поддерживай вложенные Repeater'ы в `QuizResource`.
- `QuestionReference` — это связь "документ + страница + якорный текст". Не ломай эту механику.
- При добавлении нового типа квиза используй существующий `GeneralQuizPlayer` / `QuizPlayer` как референс.

### Работа со сменами и оплатой
- Логика расчёта `total_minutes` и `estimated_earnings` живёт в `WorkShift::booted()`.
- Для отклонений (`type === 'deviation'`) ставка и минуты берутся из `DeviationsCatalog`.
- Для обычных смен — фиксированная ставка 450 руб/час (на момент написания).
- При изменении этой логики обновляй миграции/модели/тесты и документируй.

### Аналитика (ClickHouse)
- Любое значимое действие пользователя должно логироваться через `ClickHouseService::log()` **или** `ActionLog::log()` (он сам форвардит в ClickHouse один раз). Не оба вызова на одно событие.
- Не пиши напрямую в ClickHouse из контроллеров (`new Client` + `insert`) — только сервис + очередь `analytics`.
- Чтение `user_actions` (история журнала, виджеты Filament) — `ClickHouseService::select()`. При `fake()` и при недоступном ClickHouse возвращает пустой список.
- События без сессии (Telegram и т.п.) не отбрасывать: `user_id=0`, `user_role=system`.
- Логировать выдачу доступа (click / JSON show / страница материала), а не каждый Range-запрос к signed URL.
- PDF общего наряда (`GET /naryady/{id}/pdf`): `view_naryad_pdf` только без `Range` или с `bytes=0-`.
- Не логировать обычные просмотры страниц.
- Схема таблицы `user_actions` важна: `event_date`, `user_column` (из профиля), `action_type` и т.д.
- Auth: `LogAuthenticationEvents` на Login/Failed/Logout. `via` = `web` / `filament` / `mobile` по path. Успех: `login` или `mobile.login`. Неуспех: `login_failed` (`reason` = `credentials` | `inactive`), без пароля. Выход: `logout` с `$event->user` (после logout `Auth::user()` пуст). Mobile token: явный `mobile.logout` (`via=token`), событие Logout не стреляет.
- Filament CRUD: `AdminPanelAudit` → `admin.{model}.{created|updated|deleted}` через `ActionLog`. Только пока `Filament::isServing()` и `canAccessPanel`. Пароль — имя поля в `changed`, не значение. Query-builder/импортёр события модели не шлёт.
- CH-only мутации: смены `work_shift.saved`, подстройки `podstroika.*`, НСИ `nsi.*`, журнал `journal.*` + `normative_completed`, учёт `uchet.*`, печать `naryad.print` (лист, не partial), поиск инструктора `instructor_*`.
- Artisan импорт/выгрузка: одно событие на прогон (`arm.import_dbf`, `arm.export_lsbuh`, `arm.golden_month`), `user_id=0` / `system`. Не логировать каждую строку DBF.
- ActionLog: документы/обучение/квиз (`quiz_opened` после успешного `playerData`), комментарии/реакции, наряды (`view_naryad_personal`, `view_naryad_pdf`), bug-report, backstage.
- Нарушения регламента: колонка `action_logs.is_violation` и `ActionLog::violation()`. Имя `violation_*` само ставит флаг. В ClickHouse флаг только в `details.is_violation` (схема `user_actions` не меняется). Клиентский `/api/actions/log` флаг не ставит. Стрим signed URL при 403 не логируется (Range). Алерты (`AdminNotification` / Telegram) — для скачивания и для мобильных попыток снимка/записи/шаринга/печати (`POST /api/mobile/documents/{document}/violation`), не для каждого отказа в просмотре. `DocumentReglamentService` — общая точка.

### Filament
- Основная панель: `/admin` (`AdminPanelProvider`).
- Цвет — Amber.
- Группы меню: «Люди и доступ», «Документы», «Обучение», «Справочники смен», «Администрирование», «Коммуникации».
- «Коммуникации» всегда видна для `isAdmin()`, даже при `CHAT_ENABLED=false`.
- Дашборд: очередь заявок/уведомлений/устройств + виджеты аналитики в `app/Filament/Widgets/`.
- Выкладка нарядов (`NaryadResource`): TXT + разбивка смен + общий PDF. Сетку `/naryad` сюда не дублировать.
- Новые ресурсы создавай в `app/Filament/Resources/` с полными Pages (List/Create/Edit).

### Docker и окружение
- При обновлении PHP-зависимостей сохраняй `composer.config.platform.php = 8.2.0`; проверяй реальный PHP контейнера через `composer check-platform-reqs --no-dev`.
- Обновление зависимостей безопасности и открытые mobile advisories — `SECURITY_DEPENDENCIES.md`; production устанавливает зависимости по lock-файлам.
- Основной способ запуска — `docker compose up`.
- Production + дисциплина + чат: `docker compose -f docker-compose.prod.yml -f docker-compose.discipline.yml -f docker-compose.chat.yml`.
- Redis — только кеш/сессии. `QUEUE_CONNECTION=deferred` на `arm-app`. `QUEUE_CONNECTION=database` **только** на `arm-chat-worker`. Не `docker exec arm-app queue:work --queue=chat`.
- `docker/php-fpm/www.conf` → `/usr/local/etc/php-fpm.d/zz-www.conf`, `pm.max_children=15` (фото; текстовый пилот можно на стоковых 5).
- При локальной разработке без Docker помни про зависимости (postgres host = `postgres`, clickhouse = `clickhouse` и т.д. в конфигах).
- Миграции и сиды должны работать в контейнере.

### Роли и доступ
- CSRF обязателен для web, Filament и Livewire; `X-Livewire` не является основанием для исключения.
- `CheckUserExistence` проверяет активность также на Livewire; в Filament auth middleware сохраняется при обновлении компонентов.
- Исключения `CheckDeviceBinding` / `CheckDynamicBarrier` — только `AccessBarrierExemptions` (Livewire/Filament lifecycle и `admin/serve-*` не снимать).
- Mobile API: после `auth:sanctum` обязателен `EnsureActiveApiUser`. Деактивация через модель отзывает токены;
  массовый query-builder/SQL update не запускает этот отзыв (проверка активности всё равно блокирует запросы).
- План усиления безопасности и ручная выкладка без CI/CD — `SECURITY_ROADMAP.md`.
- Роли — `App\Enums\UserRole`. Канонический нарядчик: `dispatcher`. Строка `naryadchik` только как алиас в `UserRole::safeFrom()` (старые JSON `allowed_roles`).
- Проверки в PHP: `User::isAdmin()`, `isSuperAdmin()`, `isInstructor()`, `isDispatcher()`, `isDriver()`, `isStudent()`, `canBypassAccessBarriers()`, `canViewRospisiStatistics()`, `canAccessByRoles()`.
- Filament (`canAccessPanel`): основная панель `admin` — только `SUPER_ADMIN` и `ADMIN`. Отдельная панель `discipline` — активные `TORMOZNIK` / `NACHALNIK`, без discovery общих ресурсов и виджетов.
- Журнал ТЧМ: только `instructor` — `EnsureInstructor` на всех `/journal*` (не дублировать проверку в каждом методе).
- Поиск по выданным нарядам для инструктора: `/journal/naryad-search`. Файлы (наряды, разбивка, ФИО) принадлежат `user_id`. Логика в `App\Services\InstructorNaryad\` (порт прототипа searching). Не смешивать с планированием `/naryad` нарядчика.
- PDF разбивки: `scripts/extract_shifts.py` (pdfplumber) вызывается из `BreakdownPdfExtractor`. Нужны python3 + pdfplumber в образе (`Dockerfile`).
- Планирование наряда: только `dispatcher` — `EnsureDispatcher`.
- Не пиши `in_array($role, ['super_admin', 'admin'])` и не вводи роль `naryadchik` в enum/БД.

## Правила взаимодействия с AI-ассистентом

1. **Исследование перед действием** — перед любым `search_replace` или командой, которая меняет код, я должен явно показать, какие файлы были прочитаны.
2. **План для сложного** — задачи типа "переделать систему оплаты", "добавить новый тип обучения", "рефакторинг работы с документами" требуют плана и утверждения.
3. **Маленькие итерации** — лучше несколько маленьких понятных изменений, чем один огромный PR.
4. **Тестирование** — после изменений предлагай как проверить (вручную через UI, artisan команды, тесты).
5. **ClickHouse и расчёты** — любое изменение в этих областях требует особого внимания и демонстрации понимания текущей логики.
6. **Обновление этого файла** — если архитектура или важные соглашения меняются, обновляй `AGENTS.md`.
7. **Использование навыков** — для больших задач предлагай использовать `/design`, `/implement [--effort N]`, `/review`, `/check-work`.

## Полезные команды

```bash
# Запуск всего стека
docker compose up -d

# Production + дисциплина + чат
docker compose -f docker-compose.prod.yml -f docker-compose.discipline.yml -f docker-compose.chat.yml up -d

# Логи приложения
docker compose logs -f app

# Artisan внутри контейнера
docker compose exec app php artisan ...

# Сборка фронтенда
npm run dev          # внутри контейнера или локально
npm run build

# Очистка
php artisan optimize:clear
```

## Когда спрашивать пользователя

- Любые изменения в расчёте заработка / тарифах
- Изменения в схеме ClickHouse или добавление новых событий логирования
- Изменения в логике доступа к документам / квизам
- Добавление новых ролей или изменение `canAccessPanel`
- Крупные рефакторинги фронтенд-компонентов

---

**Последнее обновление:** 2026-10-26 (чат: пилот депо, Redis только кеш/сессии, `pm.max_children=15`)

Этот файл имеет высокий приоритет и будет автоматически подгружаться в контекст AI при работе в директории проекта.


## Исполнительская дисциплина (23 октября 2026)

- Документация и выкладка: `DISCIPLINE.md`.
- Роли `tormoznik` / `nachalnik` не являются администраторами и не обходят барьеры. Только собственная панель `/discipline-admin/discipline-monitor`.
- Тормозник управляет своими карточками; начальник — всеми карточками модуля этого экземпляра ARM; инструктор — только своим исполнением. Междеповского разграничения пока нет.
- `DisciplineCard` → `DisciplineAssignment` (отдельно на инструктора) → `DisciplineReport` → `DisciplineFile`. Не переиспользовать `JournalTask::completeTodo` для сдачи отчётов.
- Мутации только через `DisciplineService`: транзакция, блокировка, версия, идемпотентность, проверка текущих ролей/активности. Историю не удалять и не переписывать после решения.
- Внутренние уведомления сохраняются транзакционно в PostgreSQL; мониторинг Filament опрашивает PostgreSQL каждые 15 секунд. Redis и существующая deferred-аналитика не меняются.
- Новые события `discipline.create|submit|review` идут через ClickHouseService после COMMIT; отказ аналитики не отменяет поручение.
- MinIO: приватный префикс `discipline/`, выдача через FileProxyService после проверки доступа, скачивание без inline-просмотра. Антивирус обязателен, при недоступности — 503. Compose overlay `docker-compose.discipline.yml` добавляет ClamAV к production-сети backend.
- Тесты: `DisciplineTest`, `DisciplineScannerTest`; реальные гонки PostgreSQL — `tests/Support/discipline-concurrency.php`, только изолированная база `arm_discipline_test_*`.


## Мобильная исполнительская дисциплина (24 октября 2026)

- `Api/Mobile/DisciplineController`: только текущая роль instructor, только свои исполнения; Sanctum + EnsureActiveApiUser обязательны.
- `/api/mobile/discipline`: список/карточка/история/прочтение/сдача; файлы через `/discipline/files/{file}` с токеном и новой проверкой доступа, без публичных MinIO/signed URL.
- Сдача только через общий DisciplineService, те же версии, UUID операций, лимиты и антивирус. Новых таблиц и отдельного обхода проверки нет.
- Мобильные экраны: DisciplineListScreen / DisciplineDetailScreen / DisciplineAttachmentScreen. Долговременные черновики и офлайн-очередь: mobile/src/discipline/queue.cjs + storage.js, приватное хранилище по API/user id; запись до отправки, тот же UUID после перезапуска. Повторы только в активном приложении. Повторный вход сохраняет очередь, выход удаляет текущий scope.
- Документация: `mobile/DISCIPLINE.md`; тесты `MobileDisciplineTest`, `mobile/tests/discipline.test.cjs`.


## Мобильный просмотр журнала (25 октября 2026)

- JournalOverview — общая сводка веб-журнала и API, только текущая колонна.
- Api/Mobile/JournalController: GET /journal, /journal/tasks, /journal/crew, Sanctum + активность + свежая роль instructor. Чужую колонну и владельца клиент не выбирает.
- GET crew не создаёт нормативы/настройки, выдаёт только записи своего instructor_id. Нормативные даты берутся из БД без повторного расчёта.
- Экраны JournalScreen / JournalTasksScreen / JournalCrewScreen открываются из раздела поручений. Это просмотр без долговременного кеша; мутации журнала пока только в вебе.
- Документация mobile/JOURNAL.md, тесты MobileJournalTest.


## Чат (пилот депо)

Модель B (в монолите): полный inbox, FileProxy, опрос 15 с, PHP-worker без брокера (нет RabbitMQ/NATS/Kafka/Redis Streams/Reverb/FCM/WebSockets). Карусель учёта не трогать.

- `CHAT_ENABLED` (по умолчанию false) включает мобильный API и вкладку. Группа Filament «Коммуникации» для `isAdmin()` не прячется.
- `CHAT_PILOT_COLUMN` — необязательный фильтр колонны на мобильном inbox.
- `CHAT_ENCRYPTION_KEY` — `base64:` 32 байта как APP_KEY; пустой ключ закрывает encrypt/decrypt. `CHAT_PREVIOUS_KEYS` — через запятую, для ротации. Encrypter AES-256-GCM envelope `v1.{kid}.{payload}`; не Crypt facade.
- Текст синхронно (воркер не нужен). Фото/PDF: ClamAV + `ProcessChatAttachment` в таблице `jobs`. `QUEUE_CONNECTION=database` только на `arm-chat-worker`; на `arm-app` остаётся `deferred`. Не `docker exec arm-app queue:work --queue=chat`.
- Redis — только кеш/сессии. Файлы через FileProxyService, не `Storage::temporaryUrl()`. Audit Filament: `admin.chat_room.*` и `admin.column_deputy.*`, без тел сообщений. Антивирус — `ClamAvScanner` + `config/antivirus.php`, не `config('discipline.*')`.
- Compose: `docker-compose.prod.yml` + `docker-compose.discipline.yml` + `docker-compose.chat.yml`.
- `docker/php-fpm/www.conf`: `pm.max_children=15`. Откат: `CHAT_ENABLED=false` (воркер idle).

Пилот:

1. Резервная копия Postgres и MinIO `chat/`.
2. `php artisan migrate`.
3. Выставить `CHAT_ENCRYPTION_KEY` (пустой — fail closed).
4. Пересоздать `arm-app`.
5. Filament уже открыт → создать **одну** комнату вручную при `CHAT_ENABLED=false`.
6. APK `/install` (1.1.0 / versionCode 8).
7. `CHAT_ENABLED=true`, при необходимости `CHAT_PILOT_COLUMN`.
8. Текст работает без воркера. Фото: `pm.max_children ≥ 15` + `up -d chat-worker`; проверить `docker top arm-chat-worker`.
9. Откат `CHAT_ENABLED=false` — воркер простаивает.
