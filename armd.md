# Клон АРМ-ЛБ (FoxPro → ТЧ-15)

Живая схема переноса учёта нарядчика из FoxPro 2.5 в этот репозиторий.
Стек клона — **текущий Laravel 12 + Postgres + Redis**, не отдельный Next/Nest.
Журнал ТЧМ, обучение, росписи и мобильное приложение не входят в паритет АРМ и здесь не переписываются.

Эталон исходников: каталог `ARMNRD` (Datanrd / PROGNRD).

---

## Соответствие уже существующего кода FoxPro

| FoxPro | Уже в `arm/` | Комментарий |
|---|---|---|
| `USERS` / роли нарядчик | `users.role = dispatcher` | Оператор учёта ЛС ещё не заведён |
| `LKM` | `users` + `user_profiles` + **шаг 2:** `arm_personnel` | Карточка АРМ отдельно от профиля портала |
| `GRAF` | `schedule_types` | С шага 1: колонка `foxpro_code` |
| `KALEND` | `naryad_quotas` | Дата + тип графика + квота составов; окно не ограничено 90 днями |
| `NAZN` / должности | **шаг 2:** `arm_appointments` | История должности/класса с датой |
| `OTVM` | **шаг 2:** `arm_absences` + клетка сетки | Период с–по и код вида |
| `POZEL` | `podstroikas` | Заявки на месяц, не привязка к дню/маршруту как в АРМ |
| `NARJAD` | `naryad_assignments` | До шага 1 — только номер маршрута, без нарезки часов |
| `RAZBSM` | **шаг 1:** `arm_shift_breakdowns` | Разбивка смены |
| `PRAZD` | **шаг 1:** `arm_holidays` | Праздничные дни |
| `CHAS2`, `LSH`, `FLSM`, `LSBUH` | нет | Следующие шаги |

Сетка уже рисуется на **календарный месяц** (28–31 день). Календарь графиков пишется **кнопкой**, без дискеты.

---

## Шаг 1 — разбивка смены, праздники, часы наряда, импорт DBF

Цель шага: назначение в сетке берёт часы не «конец − начало ≈ 8 ч», а из разбивки как в `RAZBSM` (`CHAS`, линия, резерв, ночь, вечер, разрыв).

### `schedule_types` (дополнение)

| Колонка | Тип | FoxPro |
|---|---|---|
| `foxpro_code` | `string(8)`, unique nullable | `GRAF.KODEL` |

### `arm_shift_breakdowns` ← `RAZBSM`

Уникальность: `(graph_code, route_code, shift_code, sequence, position_code)`.

| Колонка | FoxPro | Смысл |
|---|---|---|
| `schedule_type_id` | через `GRAF` | FK на тип графика дня |
| `graph_code` | `GRAF` | Код графика |
| `route_code` | `NM` | Номер маршрута/состава |
| `shift_code` | `SM` | Смена |
| `sequence` | `NOM` | Порядок в разбивке |
| `position_code` | `DOL` | М / П и т.п. |
| `start_hours`, `end_hours` | `NACH`, `OKON` | Начало/конец, формат `чч.мм` как в АРМ |
| `hours_total` | `CHAS` | Всего часов |
| `hours_line`, `hours_line_2` | `LIN1`, `LIN2` | Линия 1 / 2 лица |
| `hours_reserve`, `hours_reserve_2` | `CHASR1`, `CHASR2` | Резерв |
| `hours_night`, `hours_night_reserve` | `NOCH1`, `NOCHR1` | Ночь |
| `hours_night_2`, `hours_night_2_reserve` | `NOCH2`, `NOCHR2` | Ночь 2 лица |
| `hours_evening`, `hours_evening_reserve` | `VECH1`, `VECHR1` | Вечер |
| `hours_evening_2`, `hours_evening_2_reserve` | `VECH2`, `VECHR2` | Вечер 2 лица |
| `hours_break`, `hours_break_reserve` | `RAZR1`, `RAZRR1` | Разрыв |
| `hours_break_2`, `hours_break_2_reserve` | `RAZR2`, `RAZRR2` | Разрыв 2 лица |
| `appearance_start`, `appearance_end` | `PLZAST`, `PLOKON` | Явка / окончание текстом |
| `content` | `SODER` | Содержание |
| `morning_code`, `morning_shift` | `UTRO`, `SMUT` | Утренний переход |
| `notes` | `PRIM` | Примечание |

Поля 2 лица на разбивке уже хранятся; в `naryad_assignments` на этом шаге пишется только контур **1 лица** (как основная строка `NARJAD`).

### `naryad_assignments` (дополнение) ← часовые поля `NARJAD`

| Колонка | FoxPro |
|---|---|
| `arm_shift_breakdown_id` | найденная строка `RAZBSM` |
| `work_code` | `WORK` |
| `shift_code` | `SMENA` |
| `hours_total` | `CHAS` |
| `hours_line` | `LIN1` |
| `hours_reserve` | `CHASR1` |
| `hours_night` | `NOCH1` |
| `hours_night_reserve` | `NOCHR1` |
| `hours_evening` | `VECH1` |
| `hours_evening_reserve` | `VECHR1` |
| `hours_break` | `RAZR1` |
| `hours_break_reserve` | `RAZRR1` |
| `hours_holiday` | `PRAZD1` |
| `hours_holiday_reserve` | `PRAZDR1` |
| `hours_overtime` | `PERERAB` |
| `two_person` | `REZM2L` |

### `arm_holidays` ← `PRAZD`

| Колонка | FoxPro |
|---|---|
| `holiday_date` unique | `KODEL` |
| `name` | `NAZEL` |

### Сервисы и команды

- `App\Services\Arm\DbfReader` — чтение FoxPro DBF (CP866).
- `App\Services\Arm\ShiftHoursService` — поиск разбивки по дате (тип графика дня из `naryad_quotas`) + коду маршрута/смены; запись часов в назначение.
- `php artisan arm:import-dbf {path}` — `GRAF`, `RAZBSM`, `PRAZD`, `KALEND`, с шага 2 ещё `LKM`, `NAZN`, `OTVM`.
  Пустые строки `RAZBSM` (нет графика/маршрута/смены) пропускаются.

Интерфейс нарядчика: разделы «Разбивки смен» и «Праздники».

При `POST /naryad/assign` часы подставляются из разбивки, если она найдена; иначе остаётся прежняя оценка по времени маршрута.

---

## Шаг 2 (текущий) — картотека, назначения, периоды отвлечений

Цель: люди из `LKM` становятся водителями портала, история должностей и больничных/отпусков хранится датированно, как в АРМ.

Учётка портала (`user_profiles` для журнала/обучения) **не раздувается** полями FoxPro. Карточка АРМ — таблица `arm_personnel` 1:1 с `users`. При импорте в профиль синхронизируются только поля, которые сетка уже использует: табельный, телефон, должность, класс, бригадир, помощник.

Создание пользователя: `email = tab{TABNOM}@arm.local`, роль `driver`, пароль случайный (повторный импорт пароль не трогает). Уволенные (`DTUV` в прошлом) помечаются `is_active = false`. Поиск существующего: сначала `user_profiles.tab_number`, затем email.

Строки `NAZN`/`OTVM` с непустым `UDAL` пропускаются.

### `arm_personnel` ← `LKM`

Уникальность: `tab_number`, `user_id`.

| Колонка | FoxPro | Смысл |
|---|---|---|
| `user_id` | | FK на `users` |
| `tab_number` | `TABNOM` | Табельный |
| `full_name` | `FIO` | Как в АРМ (в т.ч. хвост `+`) |
| `hired_on` | `DTUSTR` | Приём |
| `fired_on` | `DTUV` | Увольнение |
| `position_code` | `DOLZN` | МШ / П/М |
| `class_code` | `KLASS` | Класс |
| `phone_primary`, `phone_secondary` | `TEL1`, `TEL2` | |
| `seniority_on` | `DTVISL` | Выслуга с |
| `brigade_code` | `NBRIG` | Бригада |
| `is_brigadier` | `PBR` = `+` | |
| `shop_code` | `CEH` | Цех |
| `med_from`, `med_to` | `DTMED1`, `DTMED2` | Медкомиссия |
| `assistant_seniority_on` | `DTVISLP` | Выслуга П/М |
| `roster_number` | `NOMER` | Номер в списке |
| `assistant_seniority_years` | `VISLP` | |
| `early_windows` JSON | `RANVR1..3` + `PRAN1..3` | `[{time, reason}]` ранний выход |
| `late_windows` JSON | `POZOK1..3` + `PPOZ1..3` | Позднее окончание |
| `brigadier_from`, `brigadier_to` | `DTBRIG`, `DTOSBRIG` | |
| `category_code` | `KAT` | Категория |
| `premium_flag` | `OTB_PREM` | Отбор на премию |
| `main_tab_number` | `TABNOM1` | Основной табельный подработчика |
| `depo_code` | `DEPO` | |

Синхрон в `user_profiles`: `tab_number`, `phoneNumber` ← `TEL1`, `position` ← `DOLZN`, `normative_class` ← `KLASS`, `is_brigadir` ← `PBR`, `is_pomoshnik` если в должности есть «П».

### `arm_appointments` ← `NAZN`

Уникальность: `(tab_number, appointed_on, position_code)`.

| Колонка | FoxPro |
|---|---|
| `user_id` | по табельному |
| `tab_number` | `TABNOM` |
| `appointed_on` | `DTNAZN` |
| `position_code` | `DOLZN` |
| `class_code` | `KLASS` |

### `arm_absences` ← `OTVM`

Уникальность: `(tab_number, starts_on, ends_on, kind_code)`.

| Колонка | FoxPro |
|---|---|
| `user_id` | по табельному |
| `tab_number` | `TABNOM` |
| `starts_on` | `DTN` |
| `ends_on` | `DTK` (пустое = дата начала) |
| `kind_code` | `VIDOTV` (Б, ОТ, …) |

Интерфейс нарядчика: «Картотека», «Назначения», «Отвлечения (периоды)».

---

## Следующие шаги (ещё не в коде)

3. Контроли сетки из `PLANIR` (12 ч, 42/66, выходные, ночь→1-я, подстройки) + пересечение с `arm_absences`.
4. Часы 2 лица (`CHAS2`) на том же назначении.
5. Учётные карточки и рабочее место оператора.
6. Лицевые счета и формулы `FLSM`/`FLSP`.
7. Выгрузка `LSBUH`, отчёты `DOKMENU`, закрытие месяца.
