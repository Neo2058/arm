# Technical Debt & Refactoring Roadmap

**Project:** ТЧ-15 (ARM)  
**Last reviewed:** 2026-10-19

Снимок того, что **есть в репозитории** и что ещё открыто. Закрытое не поднимать без новой причины.

---

## Что есть сейчас (не долг)

| Область | Состояние |
|---|---|
| Журнал ТЧМ | `EnsureInstructor` на всех `/journal*` |
| Роли | `UserRole` + хелперы `User`; нарядчик = `dispatcher`; оператор учёта = `operator` |
| Файлы документов/обучения | `FileProxyService` + signed routes |
| Наряд | `Naryad/{Planning,Setka,Catalogs,Breakdowns,Personnel,Print}Controller` |
| Печать нарядов | `NaryadPrintService` (`pecnar`): `/naryad/partial/print`, `/naryad/print` (`kind` = full или extract) |
| Выкладка нарядов | `NaryadIssuePublisher` + `/naryady` (личный TXT + общий PDF); журнал — `/journal/naryad-search` |
| Маршруты | `routes/*.php` по доменам, в т.ч. `uchet.php` |
| Заявки `/about` | валидация полей, согласие, проверка табельного/ФИО в БД |
| Мобильный API | Sanctum `/api/mobile`: логин, документы, обучение, комментарии, signed файлы |
| Мобильный клиент | Expo в `mobile/`; установщики APK/IPA — см. `mobile/README.md`; страница `/install` |
| Учёт / ЛС | `/uchet` (`EnsureUchetStaff`), импорт DBF, формулы, закрытие месяца (`armd.md`) |
| Тесты | journal, roles, files, naryad, print, поиск ФИО, routes, access request, mobile API, install, учёт |

---

## Resolved (2026-09)

| Было | Как закрыто |
|---|---|
| Журнал проверял instructor только в `index()` | `EnsureInstructor` |
| Строковые роли, `naryadchik` | enum + хелперы; алиас только в `safeFrom()` |
| Дубли стрима документов/обучения | `FileProxyService` |
| God-контроллер наряда | split + `NaryadHoursCalculator` / Arm-сервисы |
| Монолитный `web.php` | доменные route-файлы |
| Почти не было тестов | набор Feature-тестов |
| Форма `/about` без серверной проверки «уже есть пользователь» | `StoreAccessRequestRequest` |
| Нет мобильного канала | `/api/mobile` + `mobile/` + `/install` |
| Нет печати наряда как в `pecnar` | `NaryadPrintService` + `PrintController` (2026-10-12: RASST/TEXTN/PODPISI; нет диалога `who_msh`) |
| Поиск ФИО в PDF на клиенте (pdf.js) | серверный `POST /naryady/search-people` |

---

## Still open

### 1. CSRF + Livewire workarounds
2026-10-08: обходы в `VerifyCsrfToken` и skip активности на Livewire удалены, подключение web middleware исправлено.
Проверено локально; браузерный smoke HTTPS/Filament и production-выкладка ещё не выполнены.
`SESSION_DOMAIN`/cookie-конфигурация не менялась. Статусы и порядок выкладки — `SECURITY_ROADMAP.md`.

### 2. Исключения device/barrier: списки сведены
`AccessBarrierExemptions` — общий список для `CheckDeviceBinding` и `CheckDynamicBarrier`.
Livewire/Filament (`livewire*`, `filament*`, `livewire.*`) и `admin/serve-*` оставлены: FileUpload preview и `/livewire/update` ломаются редиректом.
`CheckUserExistence` по-прежнему проверяет Livewire. Панель `/admin` эти два middleware не запускает (свой стек).
Новые URI в исключения — только через этот класс.

### 3. Filament FileUpload и прямые URL
Росписи `/rosisi` и общий PDF нарядов идут через `FileProxyService`. Filament `FileUpload` (disk / directory / visibility / `getUploadedFileUsing`) не трогать мимоходом.

### 4. Filament `getUploadedFileUsing`
Крупные замыкания в `DocumentResource` / `TrainingMaterialResource`. Вынос только без смены disk / visibility / формы ответа.

### 5. Логи: канал закрыт
Auth, Filament CRUD, мутации, `is_violation`, artisan-прогоны (`arm.import_dbf` / `arm.export_lsbuh` / `arm.golden_month`) пишутся. Query-builder по строкам DBF специально не логируется. `AdminNotificationService` — алерты, не журнал действий.

### 6. Два расчёта зарплаты
`WorkShift::booted()` (хардкод ставок) и `PayrollService`. Параллельно появился учёт `/uchet` (формулы FoxPro / ЛС) — не смешивать со ставкой 450 в `WorkShift` без явной задачи.

### 7. Мобильный прототип (ограничения, не баги)
- Нет device binding / барьера на `/api/mobile`.
- APK/IPA **не** в git (класть в `public/downloads/` после EAS).
- Нет журнала, наряда, квизов, росписей, учёта в приложении — так задумано.
- iOS-раздача без Apple Developer невозможна.

### Не долг: JS → TypeScript
Web — ~4k строк JSX-островов, мобильный — ~700 строк Expo JS. Открытый долг выше — PHP (CSRF, три Check-middleware, MinIO, Filament, два расчёта зарплаты). Массовый перевод на TS **до** этих пунктов не закрывает ни один из них, трогает живой UI без тестов и пересекается с только что переписанным `NaryadViewer`. Если понадобится TS — только на новых файлах или отдельным шагом после долга, не как предусловие.

---

## Operational / lower

| Area | Status |
|---|---|
| Тесты | Нет e2e Livewire-загрузки Filament; журнал/наряд покрыты слабо по бизнес-правилам |
| ClickHouse | Запись через `ClickHouseService::log` (очередь `analytics`); чтение виджетов и истории журнала — `ClickHouseService::select` |
| Webhooks ЮKassa / Telegram | В auth-группе (`support.php`) |
| Backup / monitoring | Не описаны |
| Журнал Q&A | Ответ только из `extracted_text`; извлечение текста PDF при загрузке ещё не сделано |
| Бинарники APK/IPA | Собирать по `mobile/README.md`, не коммитить |

---

## How to work with remaining debt

- Новые файлы документов/обучения — только `FileProxyService`.
- Filament FileUpload и CSRF/Livewire — не подчищать мимоходом.
- Новые исключения device/barrier — только в `AccessBarrierExemptions`.
- Роли — хелперы `User` / `UserRole` (`operator`, `dispatcher`, не `naryadchik`).
- Маршруты — в `routes/*.php`.
- Мобильная сборка и состав функций — **`mobile/README.md`**, не дублировать вслепую.
- Учёт FoxPro / ЛС — **`armd.md`**.

**Goal:** Keep the application maintainable while private files and access stay solid.
