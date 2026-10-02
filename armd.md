# Клон АРМ-ЛБ (FoxPro → ТЧ-15)

Живая схема переноса учёта нарядчика из FoxPro 2.5 в этот репозиторий.
Стек клона — **текущий Laravel 12 + Postgres + Redis**, не отдельный Next/Nest.
Журнал ТЧМ, обучение, росписи и мобильное приложение не входят в паритет АРМ и здесь не переписываются.

Эталон исходников: каталог `ARMNRD` (Datanrd / PROGNRD).

---

## Соответствие уже существующего кода FoxPro

| FoxPro | Уже в `arm/` | Комментарий |
|---|---|---|
| `USERS` / роли | `dispatcher` + **шаг 5:** `operator` | Нарядчик — сетка; оператор — `/uchet` |
| `LKM` | `users` + `user_profiles` + **шаг 2:** `arm_personnel` | Карточка АРМ отдельно от профиля портала |
| `GRAF` | `schedule_types` | С шага 1: колонка `foxpro_code` |
| `KALEND` | `naryad_quotas` | Дата + тип графика + квота составов; окно не ограничено 90 днями |
| `NAZN` / должности | **шаг 2:** `arm_appointments` | История должности/класса с датой |
| `OTVM` | **шаг 2:** `arm_absences` + клетка сетки | Период с–по и код вида |
| `POZEL` | **шаг 3:** `arm_day_adjustments` + `podstroikas` | Подстройка на дату (маршрут/смена); заявки на месяц — отдельно |
| `NARJAD` | `naryad_assignments` | До шага 1 — только номер маршрута, без нарезки часов |
| `RAZBSM` | **шаг 1:** `arm_shift_breakdowns` | Разбивка смены |
| `PRAZD` | **шаг 1:** `arm_holidays` | Праздничные дни |
| `CHAS2` | **шаг 4:** поля `*_2` на `naryad_assignments` | Часы 2 лица на той же клетке |
| `LSH` / `FLSM` / `FLSP` | **шаг 6:** `arm_accounts` + `arm_pay_formulas` | Итоги месяца + интерпретатор `IIF` |
| `LSBUH` | **шаг 7:** `/uchet/lsbuh.csv` | Выгрузка бухгалтерии |

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

## Шаг 3 (текущий) — контроли сетки `PLANIR`

Назначение в клетке (`POST /naryad/assign`) **блокируется** при нарушении (в DOS отчёт строился после заполнения). Сообщения те же по смыслу, что в `PLANIR.PRG` / `ctrl_str`.

Сервис: `App\Services\Arm\PlanirRulesService`. Параметры — JSON `naryad_norms.planir` (если пусто, берутся значения по умолчанию как в `NRDPAR`).

| Правило | FoxPro | Поведение |
|---|---|---|
| Пересечение с отвлечением | `nootv` / `OTVM` | Нельзя поставить рабочую смену в период `arm_absences`. Отвлечение в клетке (код из справочника) — можно. |
| Подстройка дня | `nopoz` / `POZEL` | Если есть `arm_day_adjustments` на дату, маршрут и смена должны совпасть. |
| После ночной — 1-я | `no56s` | Вчера ночь (`3+`,`4+`,`5+` или «ночь» в ключе) → сегодня смена `1`. |
| После 1-й — выходной | `no1s` | Вчера смена `1` → сегодня отдых (код `В`/`Е`/`ВЫХ` или отвлечение). |
| Интервал 12 / 42 / 66 ч | `noint`, `v42`, `v66` | Между окончанием предыдущей смены и началом этой ≥ 12 ч; если вчера выходной — 42; два выходных — 66. Время из разбивки (`NACH`/`OKON` как `чч.мм`). |
| 3 дня между выходными | `min3v` | Два одиночных выходных не ближе чем через 3 дня (подряд идущие В+В можно). |
| Дней без выходного | `novih` | Не больше `max_days_without_rest` (12) рабочих дней подряд. |
| Ранние подряд | `ran3` | Не больше двух смен подряд с началом в окне `early_from`…`early_to` (по умолчанию 5.00–8.00). |
| Смены 0–5 ч | `sm0_5` | Не больше двух смен подряд, у которых начало или конец в 0.00–5.00. |
| Раньше раннего выхода | `noran` | Начало раньше окна из `arm_personnel.early_windows`. |
| Позже позднего окончания | `nopozd` | Окончание позже окна из `late_windows`. |
| Норма часов | `nonorm` + уже было | Неделя / месяц / год из `naryad_norms` (как шаг 1). |

Выходной в сетке: ключ клетки есть в `deviations_catalog` **или** код смены/маршрута из `planir.rest_codes`.

### `naryad_norms.planir` JSON

| Ключ | По умолчанию | FoxPro |
|---|---|---|
| `interval_hours` | 12 | `nr_12` |
| `rest_day_hours` | 42 | `nr_1vih` |
| `two_rest_days_hours` | 66 | `nr_2vih` |
| `min_days_between_rests` | 3 | `lastvih` |
| `max_days_without_rest` | 12 | `nr_novih` |
| `early_from` / `early_to` | 5.00 / 8.00 | `nr_2ran1` / `nr_2ran2` |
| `night_shift_codes` | `["3+","4+","5+"]` | `nr_nsm1` / `nr_nsm2` |
| `rest_codes` | `["В","Е","ВЫХ"]` | `kodvih` |
| `enforce_after_first` | true | |
| `enforce_after_night` | true | |

### `arm_day_adjustments` ← `POZEL`

Уникальность: `(tab_number, plan_date)`.

| Колонка | FoxPro |
|---|---|
| `user_id` | по табельному |
| `tab_number` | `TABNOM` |
| `plan_date` | `DTP` |
| `route_code` | `NM` |
| `shift_code` | `SM` |

Импорт: `php artisan arm:import-dbf --only=adjustments`.

---

## Шаг 4 — часы 2 лица (`CHAS2`)

На том же `naryad_assignments`, не отдельная строка сетки.

| Колонка | FoxPro `C2ммгг` / `CHAS2` |
|---|---|
| `two_person` | `REZM2L` |
| `hours_line_2`, `hours_night_2`, `hours_evening_2`, `hours_break_2`, `hours_holiday_2` | `LIN2` `NOCH2` `VECH2` `RAZR2` `PRAZD2` |
| `hours_*_2_reserve` | `LIN2P` `NOCH2P` … |
| `hours_total_2` | `CHAS` файла `CHAS2.DBF` |

Если в разбивке `RAZBSM` заполнены `LIN2`/`NOCH2`, `ShiftHoursService` ставит `two_person` и копирует 2 лицо при назначении. Импорт: `php artisan arm:import-dbf --only=chas2`.

---

## Шаг 5 — учётные карточки и оператор

Роль `users.role = operator` (оператор учёта). Нарядчик и админ тоже ходят в `/uchet`. Сетку `/naryad` оператор не открывает.

Учётная карточка — не отдельная таблица, а наряды сотрудника за месяц: линия, 2 лицо, ночь, резерв. Оператор может править часы, пока месяц открыт.

---

## Шаг 6 — лицевые счета и формулы

### `arm_pay_formulas` ← `FLSM` / `FLSP`

Уникальность `(kind, nom)`. `kind`: `machinist` (`FLSM`) или `assistant` (`FLSP`).

| Колонка | FoxPro |
|---|---|
| `nom` | `NOM` |
| `name` | `NAZV` |
| `percent` | `PROCR` |
| `pay_code` | `VIDOPL` |
| `tariff_code` / `tariff` | `TARIF` / `N_TARIF` |
| `cost_code` | `SHZAT` |
| `formula` | `FORMULA` (`IIF`, `.AND.`, сравнения) |

Интерпретатор: `App\Services\Arm\FormulaInterpreter`. Переменные — поля ЛС в нижнем регистре (`vchas1`, `vc1`, `per1`, `dob1`, `nvih`, …). Нет поля → 0.

### `arm_accounts` + `arm_account_lines`

Сборка: `AccountBuilder` суммирует наряды за `YYYY-MM` в `totals` (как `LSH.VCHAS1/VC1/…`) и для каждой формулы считает часы строки. `МШ` → `FLSM`, должность с «П» → `FLSP`.

Импорт формул: `php artisan arm:import-dbf --only=formulas`.

---

## Шаг 7 — `LSBUH`, отчёты, закрытие месяца

### `arm_periods`

| Колонка | Смысл |
|---|---|
| `year_month` unique | `YYYY-MM` |
| `status` | `open` / `closed` |
| `closed_at`, `closed_by` | кто закрыл |

Закрытый месяц: сетка не назначает и не снимает смены, карточки не правятся. Открыть снова может нарядчик или админ.

Выгрузка `/uchet/lsbuh.csv?month=YYYY-MM` — колонки как `LSBUH`: `GODMES,TABN,VOPL,TARST,PROCNT,VROTR,ZAKAZ,PROF,NOMLS,DDE,OSN_PROF,TABN_OLD,DEPO,DEPOKOM`.

Отчёты `/uchet/reports`: затраты времени (линия / 2 лицо / ночь / вечер / праздник) и отвлечения за месяц (`OTVM`).

---

## Печать нарядов (`DOKMENU` / `pecnar`)

Пункт «Печать нарядов» и «Выписка в комнату отдыха» собирают документ **на одну дату** из:

- типа графика дня (`naryad_quotas` + `schedule_types.foxpro_code` ← `KALEND`/`GRAF`);
- слотов `arm_shift_breakdowns` этого графика (порядок маршрута, как `RAZBSM` index `nm`);
- назначений `naryad_assignments` на дату.

Сервис: `App\Services\Arm\NaryadPrintService`. Экраны: `/naryad` → «Печать нарядов», чистый лист `/naryad/print?date=YYYY-MM-DD&kind=full|extract`.

Поведение как в FoxPro:

- полный наряд — все смены графика; пустой слот — «. . . . . . . . .», если примечание разбивки не начинается с `*`;
- выписка — только смены `1`, `3+`, `4+`; у 1-й скрыт конец, у ночи скрыто начало;
- МШ слева, П/М справа; больше двух на линии 1–35 — предупреждение, лишние не в строке;
- «отп.» если ближайшая предыдущая запись без смены — отпуск `ОД`/`ОТ`; «!» если последнее назначение на должность моложе года;
- под нарядом — люди с отвлечениями из `deviations_catalog` (аналог `ELKODIF` kodspr=52);
- примечание `RAZBSM.PRIM`: `*` не печатает пустой слот; `\` — абзац с отступом.

Ещё нет: составы вагонов `RASST`/`RASST1`, диалог «кого назначить машинистом», `TEXTN`, таблица подписей `PODPISI`. Флаги печати — `naryad_norms.planir.print` (по умолчанию как `NRDPAR` Печатников: маршруты 1–35, обе границы времени, `nr_linia=1`).

---

## Следующие шаги

Очередь до 100% паритета, статусы и карта меню — **`armd-progress.md`**.
Этот файл (`armd.md`) держит только схемы и маппинг полей. Не дублировать дорожную карту сюда.

Кратко: шаги 1–7 контура сделаны; 100% нет. Следующий код — входы формул ЛС (`dob1`/`nvih`/`prem`/выслуга/`NRCHAS`), затем золотой месяц против `LSH`/`LSBUH`.
