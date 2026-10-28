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
- Redis — кеш + очереди
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
- Больничный/отпуск/учёба в личном табеле: WorkShift v2 type=deviation, absence_kind/absence_end,
  PersonalWorkAbsenceCalculator; учёт включительных дат, без формул оплаты. payment_status=not_calculated,
  не выдавать нулевой subtotal за отсутствие права на оплату. Пересечения со сменами/периодами запрещены
  под блокировкой пользователя; absences в GET month отдельно от отработанных часов. См. MOBILE_FIXES_2026-10-27.md.
- Личный табель API: `PERSONAL_WORKTIME_API.md`, `/api/worktime/v2` (web CSRF/device/barrier)
  и `/api/mobile/worktime` (Sanctum), общий PersonalWorkTimeController. Все данные только свои.
  Изменения через WorkTimeMutationService + PersonalWorkShiftService/PersonalWorkTimeSettingsEditor:
  блокировка User, expected_revision, UUID и неизменные операционные квитанции в Postgres.
  Обновление снимка ставки — только явный refresh_snapshot; ставки наследуются с месяца изменения.
  Согласован work_time.settings_saved: ID пользователя, месяц, имена полей, без значений; после COMMIT.
  Обе миграции версии/редакций применены в общем выпуске 27 октября 2026. Интерфейсы опубликованы:
  `PERSONAL_WORKTIME_UI.md`, остров WorkTime со старым календарём отдельно и мобильный
  WorkTimeScreen из профиля. Общая клиентская модель model.mjs/controller.mjs; расчёты только на сервере.
  Не менять UUID/тело неподтверждённого запроса; конфликт требует сравнения текущей редакции.
- Месячный личный отчёт v2: `PersonalWorkTimeMonth`, `PERSONAL_WORKTIME_MONTH.md`.
  Производный пересчёт ночь–с ночи не пишет поверх сохранённых результатов; контекст соседних
  месяцев не включается в итоги дважды. Пустые дни не считаются выходными без подтверждения
  rest_dates. v1 явно исключаются из новых денежных сумм; полный прогноз всё ещё incomplete.
- Личный табель v2: `PERSONAL_WORKTIME_BACKEND.md`. `PersonalWorkShiftService` — preview/create,
  UUID, блокировка пользователя и проверка пересечений; `WorkShift::saving` вызывает общий
  `PersonalWorkTimeCalculator`, снимок ставок сохраняется. Все перерывы v2 оплачиваются.
  Старые записи — v1, текущий календарный API изолирован на v1 до подключения новых форм.
  `estimated_earnings` v2 — неполный subtotal, `calculation_complete=false`; не выдавать его
  за полную зарплату. Официальные ЛС/PLANIR не меняются. Перед будущей выкладкой миграция обязательна.
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
- Группы меню: «Люди и доступ», «Документы», «Обучение», «Справочники смен», «Администрирование».
- Дашборд: очередь заявок/уведомлений/устройств + виджеты аналитики в `app/Filament/Widgets/`.
- Выкладка нарядов (`NaryadResource`): TXT + разбивка смен + общий PDF. Сетку `/naryad` сюда не дублировать.
- Новые ресурсы создавай в `app/Filament/Resources/` с полными Pages (List/Create/Edit).

### Docker и окружение
- При обновлении PHP-зависимостей сохраняй `composer.config.platform.php = 8.2.0`; проверяй реальный PHP контейнера через `composer check-platform-reqs --no-dev`.
- Обновление зависимостей безопасности и открытые mobile advisories — `SECURITY_DEPENDENCIES.md`; production устанавливает зависимости по lock-файлам.
- Основной способ запуска — `docker compose up`.
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

**Последнее обновление:** 2026-10-25 (PHP2Shell: пин 8.2.35, `session.upload_progress.enabled=Off`)

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


## Общая картотека — 26 октября 2026

- Единственный кадровый источник: `arm_personnel`, связь `User::personnel()`; существующие сетка, учёт и формулы продолжают читать эту таблицу. `UserProfile::user()` объявлена явно: импорт привязывает карточку к действующему аккаунту по табельному, а не создаёт дубль.
- Общий экран `/personnel` (список, поиск, карточка, PATCH): активные admin/super_admin и dispatcher видят всё; instructor видит и редактирует только записи, для которых `user.profile.column` совпадает с его текущей колонной. Без колонны — пусто, чужой ID — 404. Operator имеет чтение. Driver/student и другие роли не имеют доступа.
- `PersonnelDirectory::query($actor)` — общий ограниченный запрос для модулей, `update()` — транзакционное изменение карточки и проекции общих полей в профиль. Не использовать запрос без ограничения при выдаче инструктору. Колонну может менять только администратор; она остаётся в `user_profiles`, как в журнале.
- `/admin/arm-personnels`: «Люди и доступ → Картотека машинистов», поиск, просмотр, редактирование, индивидуальное и массовое назначение колонны. Доступ к самой панели не расширен. Создание/удаление и изменение табельного/аккаунта/увольнения через этот экран выключены.
- Ссылки: картотека нарядчика, журнал и боковое меню инструктора, меню учёта. Карточки и изменения общие, отдельная копия данных для инструкторов не создаётся.
- LKM не содержит поля колонны. `NBRIG` — бригада, `CEH` — цех; автоматически превращать их в колонну нельзя. Существующие назначения сохраняются, новые сотрудники до распределения видны только общему персоналу.
- Импорт LKM целиком в транзакции; повторный табельный в источнике или конфликт аккаунтов отменяет весь прогон. Пароли, роли, полные существующие имена, колонны и instructor не перезаписываются. Уволенные driver деактивируются по прежнему правилу.
- События: `personnel.updated` (ID и имена изменённых полей), `personnel.column_assigned` (число и колонна); без телефона и ФИО в payload. `arm.import_dbf` остаётся одним событием на запуск.
- Проверки: `PersonnelDirectoryTest`, `ArmPersonnelImportTest`, `JournalAccessTest`, `FileProxyTest`, `NaryadPlanningTest`, `WebRoutesSplitTest`, `UserRoleAccessTest`, `ArmAccountingTest`, `MobileJournalTest`. Изоляция по колонне проверяется также при прямом PATCH и после переназначения колонны; проверены Livewire-редактирование и массовое назначение.


### Полнота LKM (уточнение 26 октября 2026)

Миграция `2026_10_26_100000_add_source_data_to_arm_personnel` добавляет nullable JSON `source_data` — все 36 декодированных полей исходной строки LKM (включая `OTBOR`). Рабочие поля, обе телефонные записи, три ранних/поздних окна с основаниями, медкомиссия, обе даты выслуги, числовой стаж помощника, бригадирские даты, цех, номер, категория, премиальный флаг, основной табельный и депо сохраняются. Полный исходный набор доступен только на карточке с тем же ограничением по колонне. Это снимок импорта: ручные изменения рабочей карточки его не переписывают. Оригинальный DBF сохраняется отдельно с SHA-256. В общем и административном редакторе доступны все перечисленные рабочие поля, кроме защищённых идентификаторов и даты увольнения. Колонны назначаются вручную, подтверждено владельцем: соответствия бригад/цехов нет.


## Личные профили и логины — 26 октября 2026

- `/account` GET/PATCH и `/api/mobile/account/profile` GET/PATCH используют один `SelfProfile` и рабочую карточку `arm_personnel`. Телефоны, личные и кадровые поля можно редактировать самостоятельно; профиль и картотека синхронизируются транзакционно через `PersonnelDirectory::updateOwn`. Маршрут всегда определяет пользователя из сессии/Sanctum, ID от клиента не принимается.
- Роль, активность, email-логин, табельный, колонна, instructor, увольнение и исходный снимок защищены от самоизменения (в том числе при административной роли). `account.profile_updated` пишет только имена изменённых полей. Полное ФИО расширено до 255 символов отдельной добавочной миграцией.
- Мобильный редактор `ProfileEditor` использует метаданные того же API, сохраняет ввод при сетевой ошибке, не хранит кадровые черновики на диске. Android 1.0.11 / versionCode 12. Новые нативные зависимости не добавлены.
- Логины `TABNOM@mail.ru` и случайные пароли подготовлены только для 262 активных импортированных технических аккаунтов. По прямому указанию владельца 364 архивных аккаунта исключены. Действующие реальные аккаунты не переоформляются; роли сохраняются для дальнейшего ручного назначения. Это идентификаторы входа, почтовые ящики не создаются и письма не отправляются. Пароли в открытом виде — только приватный CSV на компьютере владельца, на сервер передаются хеши bcrypt cost 12.
- Проверки: `SelfProfileTest` (веб + реальные Sanctum-токены, запрет повышения прав, чужого ID, неактивных, откат), `PersonnelDirectoryTest`, тесты маршрутов/доступа/журнала/учёта и безопасности.


## Journal task mutations (27 October 2026)

- JournalTasks is shared by web and mobile; keep personal tasks distinct from DisciplineService.
- Migration 2026_10_27_150000_version_journal_tasks adds version and UUID receipts. Published in the explicitly approved coordinated release 2026-10-27 with both worktime migrations; do not rerun personnel imports or unrelated migrations.
- Fresh role/activity, owner/column and version checked under locks. Persist receipt and mutation atomically; mismatched UUID body is 409, stale version is 412.
- Mobile journal-tasks storage keeps the exact pending request across restarts. Manual retry only; logout clears it. Stable initial TextInput defaults prevent asynchronous save from replacing typed text.
- Web forms carry UUID/version; legacy POSTs remain compatible. Existing analytics events only, after COMMIT, without duplicate replay logging.
- Published on production with Android 1.0.12 (13). See RELEASE_2026-10-27.md, mobile/JOURNAL_TASKS.md, JournalTaskMutationsTest and journal-mutations.test.cjs.
