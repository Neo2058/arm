# Technical Debt & Refactoring Roadmap

**Project:** ТЧ-15 (ARM)  
**Last reviewed:** 2026-09-17 (после полировки доступа, ролей, файлового прокси, наряда и маршрутов)

---

## Resolved (2026-09) — больше не долг

Эти пункты закрыты, не открывать заново без новой причины:

| Было | Как закрыто |
|---|---|
| Журнал ТЧМ проверял `instructor` только в `index()` | `EnsureInstructor` на группе `/journal` и на `TCHMJournalController` |
| Строковые роли, legacy `naryadchik` | `UserRole` + хелперы `User::isAdmin()`, `isDispatcher()`, `isInstructor()`, …; `naryadchik` только алиас в `UserRole::safeFrom()` |
| Дубли стрима документов/обучения | `App\Services\FileProxyService` в `DocumentController` и `TrainingController` (заголовки и signed URL не унифицировали специально) |
| God-контроллер `NaryadPlanningController` | `Naryad/PlanningController`, `SetkaController`, `CatalogsController` + `NaryadHoursCalculator` |
| Монолитный `routes/web.php` | Доменные файлы: `account`, `documents`, `training`, `work`, `journal`, `naryad`, `support` |
| Почти не было автотестов | Feature-тесты журнала, ролей, файлового прокси, наряда и снимка маршрутов |

---

## Still open

### 1. CSRF + Livewire workarounds
**Files:** `VerifyCsrfToken`, `bootstrap/app.php`, `AdminPanelProvider`, session / compose

Кастомные исключения `livewire/*`, skip в Check-middleware, нюансы `SESSION_DOMAIN` / punycode. Нужны для загрузок Filament; не ломать «заодно». На проде HTTPS уже есть (`SESSION_SECURE_COOKIE=true` в `docker-compose.prod.yml`) — workarounds стоит пересмотреть отдельно, не в том же PR, что загрузки.

### 2. Три Check-middleware с одинаковыми исключениями путей
`CheckUserExistence`, `CheckDeviceBinding`, `CheckDynamicBarrier` по-прежнему дублируют skip для Livewire / barrier / device / admin serve. Ролевой bypass уже через `User::canBypassAccessBarriers()`, но списки путей всё ещё в трёх местах.

Слияние в один `AccessControl` — отдельная задача, не трогать пути skip без регрессионных тестов 419.

### 3. Прямые MinIO URL вне прокси
`NaryadViewerController` и `RospisiController` всё ещё вызывают `Storage::disk('s3')->temporaryUrl()`. Это нарушает правило «браузер не ходит в MinIO». Перевод на `FileProxyService` — отдельный аккуратный шаг (как с документами: не менять TTL/заголовки/Filament).

**Не делать:** повторно выносить стрим документов/обучения; Filament `FileUpload` (`disk`, `visibility`, `getUploadedFileUsing`) не трогать без явного плана — прошлый раз от этого слетали загрузки.

### 4. Filament `getUploadedFileUsing`
Крупные замыкания в `DocumentResource` / `TrainingMaterialResource`. Можно вынести в хелпер **без смены** диска, directory, visibility и формы возвращаемого массива.

### 5. Логи размазаны
`ActionLog`, `ClickHouseService`, `AdminNotificationService`, прямые вызовы в контроллерах. Нет единого «это violation». `FileProxyService` стримит, но не решает, что считать нарушением.

### 6. Два расчёта зарплаты
`WorkShift::booted()` (хардкод ставок) и `PayrollService` в API. Preview и сохранённая смена могут разойтись.

---

## Operational / lower priority

| Area | Status |
|---|---|
| **Тесты** | Есть базовые feature-тесты ключевых охран; нет e2e Livewire-загрузки в Filament и мало покрытия журнала/наряда по бизнес-правилам |
| **ClickHouse из контроллеров** | Журнал иногда пишет напрямую, минуя очередь `analytics` |
| **ЮKassa / Telegram webhook** | Лежат в auth-группе (`routes/support.php`); не выносили — нет наплыва |
| **Backup / monitoring** | Нет описанного бэкапа postgres / minio / clickhouse и метрик |
| **S3 error handling** | Мало try/catch вокруг `Storage::disk('s3')` |
| **entrypoint.sh** | Смесь shell + `php -r` |
| **Demo в журнале** | `rand()` в статистике, Q&A по PDF — демо |

---

## How to work with remaining debt

- Новые **файлы документов/обучения** — только через `FileProxyService`, без прямых MinIO URL.
- **Filament FileUpload и CSRF/Livewire** — не «подчищать» мимоходом.
- Доступ — хелперы `User` / `UserRole`, не строки ролей и не `naryadchik`.
- Новые маршруты — в соответствующий `routes/*.php`, не возвращать монолит в `web.php`.
- Планирование наряда — контроллеры в `App\Http\Controllers\Naryad\`, часы — `NaryadHoursCalculator`.

**Goal:** Keep the application maintainable while the core (private files + strict access) remains solid.
