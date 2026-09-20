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
- PHP 8.2+
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
- `ClickHouseService::log($actionType, $resourceId, $details)` — **всегда используй** для фиксации действий пользователя (ставится в очередь).
- `FileProxyService` — стрим документов и training-медиа из MinIO через приложение. Не возвращай `Storage::temporaryUrl()` в браузер для новых фич. Не меняй Filament `FileUpload` (disk / visibility / `getUploadedFileUsing`) мимоходом.
- `NaryadHoursCalculator` — часы сетки наряда; формулы не дублировать в контроллерах.
- `TelegramService`
- Кастомные контроллеры в `App\Http\Controllers\Api\...`

### Маршруты
`routes/web.php` — публичные + группа `auth` + Check-middleware. Домены:

- `routes/account.php`, `documents.php`, `training.php`, `work.php`, `journal.php`, `naryad.php`, `support.php`

Новые URI клади в соответствующий файл. Имена маршрутов не ломай (на них завязан JS).

### Мобильный API (`/api/mobile`)
- Sanctum personal access token (`Authorization: Bearer`).
- JSON для логина, документов, тем обучения, материалов, комментариев.
- Стрим файлов: signed URL (`mobile.files.documents` / `mobile.files.training`) + `FileProxyService`, без сессии и без MinIO URL.
- Клиент: каталог `mobile/` (Expo). Не путать с web-группой (CSRF / device / barrier).
- Привязка устройства на мобильном прототипе **не** включена.
- Установщики (не браузер): `eas.json` профиль `preview` → APK/IPA с вшитым `EXPO_PUBLIC_API_URL`. Артефакты класть в `public/downloads/tch15-android.apk` и `tch15-ios.ipa`, страница `/install`.

### Клон АРМ-ЛБ
Схемы таблиц и шаги переноса FoxPro — **`armd.md`**. Не заводить параллельные таблицы учёта в обход этой схемы.

### Планирование наряда
Контроллеры в `App\Http\Controllers\Naryad\`:
- `PlanningController` — оболочка `/naryad`
- `SetkaController` — сетка, assign/unassign, лимиты подстроек
- `CatalogsController` — справочники
- `BreakdownsController` — разбивки смен (`RAZBSM`) и праздники (`PRAZD`)
- `App\Services\Arm\ShiftHoursService` — часы назначения из разбивки
- `App\Services\Arm\PersonnelImporter` — картотека `LKM`, назначения `NAZN`, периоды `OTVM`
- `PersonnelController` — картотека / назначения / отвлечения
- импорт DBF: `php artisan arm:import-dbf {path}` (`--only=personnel,appointments,absences`)

Доступ: `EnsureDispatcher` (роль `dispatcher`).

### Фронтенд-монтирование
Смотри `resources/js/app.jsx`. Компоненты монтируются по `id`:
- `quiz-player`
- `general-quiz-player`
- `work-calendar-root`
- `document-viewer`
- `count-down-timer`
- `carousel-menu`

Данные обычно передаются через `window.__QUIZ_DATA__`, `window.__GENERAL_QUIZ_DATA__`, `window.__DOCUMENTS_TREE__` и data-атрибуты.

## Правила разработки (обязательно соблюдать)

### Общие
- **Всегда читай** релевантные файлы (модели, ресурсы Filament, контроллеры, миграции) перед тем, как предлагать изменения.
- Для задач с неоднозначностью или большим объёмом изменений — сначала предлагай план (`/plan` или ручной план) и жди подтверждения.
- Используй `todo_write` для любых задач из 3+ шагов.
- Не трогай `vendor/`, `node_modules/`, сгенерированные билды без крайней необходимости.
- Все секреты — только через `.env` + `config/`. Никогда не хардкодь пароли/ключи.
- После правок доступа, файлов или маршрутов гоняй существующие feature-тесты (`JournalAccessTest`, `FileProxyTest`, `NaryadPlanningTest`, `WebRoutesSplitTest`, `UserRoleAccessTest`).

### Работа с квизами и документами
- При изменении структуры квиза/вопросов/ответов/ссылок на инструкции обязательно поддерживай вложенные Repeater'ы в `QuizResource`.
- `QuestionReference` — это связь "документ + страница + якорный текст". Не ломай эту механику.
- При добавлении нового типа квиза используй существующий `GeneralQuizPlayer` / `QuizPlayer` как референс.

### Работа со сменами и оплатой
- Логика расчёта `total_minutes` и `estimated_earnings` живёт в `WorkShift::booted()`.
- Для отклонений (`type === 'deviation'`) ставка и минуты берутся из `DeviationsCatalog`.
- Для обычных смен — фиксированная ставка 450 руб/час (на момент написания).
- При изменении этой логики обновляй миграции/модели/тесты и документируй.

### Аналитика (ClickHouse)
- Любое значимое действие пользователя должно логироваться через `ClickHouseService::log()`.
- Не пиши напрямую в ClickHouse из контроллеров — используй сервис + очередь `analytics`.
- Схема таблицы `user_actions` важна: `event_date`, `user_column` (из профиля), `action_type` и т.д.

### Filament
- Основная панель: `/admin` (`AdminPanelProvider`).
- Цвет — Amber.
- Дашборд содержит кастомные виджеты аналитики (`ColumnActivityChart`, `TopDocumentsChart`, `MonthlyColumnAnalytics`).
- Новые ресурсы создавай в `app/Filament/Resources/` с полными Pages (List/Create/Edit).

### Docker и окружение
- Основной способ запуска — `docker compose up`.
- При локальной разработке без Docker помни про зависимости (postgres host = `postgres`, clickhouse = `clickhouse` и т.д. в конфигах).
- Миграции и сиды должны работать в контейнере.

### Роли и доступ
- Роли — `App\Enums\UserRole`. Канонический нарядчик: `dispatcher`. Строка `naryadchik` только как алиас в `UserRole::safeFrom()` (старые JSON `allowed_roles`).
- Проверки в PHP: `User::isAdmin()`, `isSuperAdmin()`, `isInstructor()`, `isDispatcher()`, `isDriver()`, `isStudent()`, `canBypassAccessBarriers()`, `canViewRospisiStatistics()`, `canAccessByRoles()`.
- Filament (`canAccessPanel`): только `SUPER_ADMIN` и `ADMIN`.
- Журнал ТЧМ: только `instructor` — `EnsureInstructor` на всех `/journal*` (не дублировать проверку в каждом методе).
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

**Последнее обновление:** 2026-09-17 (роли, журнал, FileProxyService, split наряда и `routes/*.php`)

Этот файл имеет высокий приоритет и будет автоматически подгружаться в контекст AI при работе в директории проекта.
