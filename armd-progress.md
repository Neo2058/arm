# Направление клона АРМ-ЛБ

Снимок: **2026-10-12**.

Этот файл — дорожная карта: что уже сделано и что делать дальше.
Схемы таблиц и маппинг полей FoxPro живут в **`armd.md`**. Схемы сюда не копировать.
После каждого законченного шага обновлять статусы здесь, иначе направление снова потеряется.

---

## 1. Цель

Полный функциональный клон FoxPro 2.5 **АРМ-ЛБ** (локомотивные бригады, ТЧ-15 «Печатники»):
картотека, НСИ, сетка нарядчика с правилами `PLANIR`, учёт, лицевые счета `FLSM`/`FLSP`,
выгрузка `LSBUH`, отчёты `DOKMENU`, закрытие месяца, одновременная работа нарядчика и оператора.

**Приёмка:** часы и строки ЛС на эталонных табельных и 1–2 закрытых месяцах Печатников совпадают с старым АРМ (до копейки / 0.01 часа). Плюс живая сборка недели в сетке.

Не клонировать: DOS-экран, дискеты, дату ОС, пароль `kjhлор`, пункты 6–7 главного меню (в FoxPro не подключены).

---

## 2. Где что лежит

| Что | Путь |
|---|---|
| Код клона | `~/PhpstormProjects/arm/` |
| Схемы и шаги 1–7 | `armd.md` |
| Эта дорожная карта | `armd-progress.md` (этот файл) |
| Правила агента | `AGENTS.md` → раздел «Клон АРМ-ЛБ» |
| Эталон FoxPro | `~/Downloads/ARMNRD/` (`Datanrd` DBF, `PROGNRD` исходники CP866) |
| Импорт по умолчанию | `~/Downloads/ARMNRD/Datanrd` |

Стек **не менять**: Laravel 12 + Filament 3 + Blade/React-острова + PostgreSQL + Redis + MinIO.
Не Next.js, не NestJS, не C++.

Журнал ТЧМ, обучение, квизы, росписи, барьер, Telegram, YooKassa, мобильное Expo **вне паритета АРМ**. Их не переписывать «заодно».

---

## 3. Снимок на сегодня

Ядро сетки и контур учёта **уже можно открыть и гонять**. Это не 100% паритета.

| Контур | Оценка | Состояние |
|---|---|---|
| Планирование / сетка `PLANIR` | ~95% | Назначение блокируется правилами Печатников (формула интервала, NRCHAS, экипаж); отчёт полноты отдельно |
| Учёт / лицевые счета | ~85% | Карточки, формулы, входы `RAS1LS`, два ЛС при смене должности, повторный ЛС, МЛ, архив УК, `LSBUH.DBF`+CSV; эталон 2018-11: линия `VCHAS1` совпадает, ночь/выслуга/премия ещё нет |
| Отчёты `DOKMENU` | ~95% | Меню справок на `/uchet/reports`; печать наряда с RASST/TEXTN/PODPISI. Нет бланков «приказ», `OTV_MES` |
| НСИ | ~90% | `/nsi`: KODIF/ELKODIF, RASST, PODPISI, NRCHAS/PREM/VISL/тарифы/форма ЛС, NRDPAR; нет DOS-печати и COMKOD |

Входы `dob1` / `nvih` / `teh` / `medk` / `prem` / `visl` / `tek_nr` заполняются из TEHUCH/SPPREM/VISL/NRCHAS. Совпадение копеек с FoxPro — шаг B (эталонный месяц).

---

## 4. Сделано

### 4.1. Портал ТЧ-15 (не АРМ, не ломать)

- Роли, Filament `/admin`, журнал, обучение, документы, мобильный API.
- Сетка `/naryad` уже была: квоты, варианты, подстройки, лимиты часов.
- **Имена маршрутов `/naryad*` не переименовывать** — на них завязан JS layout.

### 4.2. Шаг 1 — разбивка, праздники, часы, импорт DBF

- `DbfReader` (CP866), `php artisan arm:import-dbf`.
- `arm_shift_breakdowns` ← `RAZBSM` (пустые строки без графика/маршрута/смены пропускаются).
- `arm_holidays` ← `PRAZD`.
- `schedule_types.foxpro_code` ← `GRAF.KODEL`.
- `ShiftHoursService`: часы назначения из разбивки, не «конец − начало ≈ 8 ч».
- `FoxTime`: `5.30` = 05:30, не дробные часы; ночь через сутки, если конец ≤ начала.
- UI нарядчика: «Разбивки смен», «Праздники».
- Сетка — **полный календарный месяц**, смена месяца **кнопкой**, без дискеты и без трёхмесячного окна DOS.

### 4.3. Шаг 2 — картотека

- `arm_personnel` ← `LKM` (1:1 с `users`, не раздувать `user_profiles`).
- `arm_appointments` ← `NAZN`, `arm_absences` ← `OTVM` (строки с `UDAL` пропускаются; пустой `DTK` = `DTN`).
- Импорт людей: `tab{TABNOM}@arm.local`, роль `driver`, случайный пароль (повторный импорт пароль не трогает).
- Уволенные (`DTUV` в прошлом) → `is_active = false`.
- Нарядчик с тем же табельным **не понижается** до водителя.
- Синхрон в профиль только: табельный, телефон, должность, класс, бригадир, помощник.
- UI: «Картотека», «Назначения», «Отвлечения». Водитель эти экраны не открывает.

### 4.4. Шаг 3 — контроли `PLANIR` (блок на `POST /naryad/assign`)

Строже DOS: в FoxPro отчёт строился после заполнения, здесь нарушение **не записывается**.

Работает:

- отвлечение `OTVM` (рабочую смену в период нельзя; клетку-отвлечение можно);
- подстройка дня `POZEL` (`arm_day_adjustments`, маршрут+смена должны совпасть);
- после ночи (`3+`/`4+`/`5+` или «ночь») → смена 1;
- после 1-й → выходной (`В`/`Е`/`ВЫХ` или отвлечение);
- интервал: формула `часы×2` (Печатники, `NR_12='-+'`), либо фикс. 12/42/66; смена 1 и продолжение с ночи — пропуск;
- не больше 3 коротких интервалов (`kolkint`, `NR_kint`; в Печатниках 0 — не стреляет);
- минимум 3 дня между одиночными выходными;
- не больше 12 рабочих дней без выходного;
- не больше двух ранних подряд и двух смен 0–5 ч подряд;
- окна раннего/позднего из карточки;
- нормы недели/месяца/года из `naryad_norms` **и** `NRCHAS.dnnr ×` рабочие дни + `NR_doppr=40`;
- `line_mode=1`: второй на ту же смену нельзя; `line_mode=2`: нельзя пару оба &lt; 1 года / оба П/М без 1 кл.;
- праздничные часы `PRAZD1` при назначении (`gath_ch`);
- автопродолжение с ночи проходит `validateAssign` (иначе откат).

Пороги — JSON `naryad_norms.planir` (импорт `NRDPAR`, по умолчанию Печатники: формула, doppr 40, линия 1, kint 0). Окно ранних 5–8 сохраняется, если в NRDPAR нули.

### 4.5. Шаг 4 — часы 2 лица

- Поля `hours_*_2` и `two_person` на **той же** строке `naryad_assignments`, не вторая клетка.
- Если в `RAZBSM` есть `LIN2`/`NOCH2`, `ShiftHoursService` копирует 2 лицо при назначении.
- Импорт: `--only=chas2`.

### 4.6. Шаг 5 — оператор и `/uchet`

- Роль `users.role = operator` (оператор учёта). Алиас UI `naryadchik` → `dispatcher`.
- Сетка `/naryad` — только `dispatcher` (`EnsureDispatcher`).
- `/uchet` — `operator`, `dispatcher`, `admin`, `super_admin` (`isUchetStaff`).
- Плитка карусели «Нарядчики» — только dispatcher; «Оператор учёта» — operator + dispatcher + админы.
- Учётная карточка = наряды человека за месяц; часы правятся, пока месяц открыт.

### 4.7. Шаг 6 — лицевые счета

- `arm_pay_formulas` ← `FLSM`/`FLSP` (`kind`: machinist / assistant).
- `FormulaInterpreter`: `IIF`, `.AND.`, сравнения. Формулы **не хардкодить**.
- `AccountBuilder` + `LsTotalsCalculator` (как `RAS1LS.PRG`) суммируют наряды за `YYYY-MM` в `totals` и считают строки.
- `МШ` → FLSM, должность с «П» → FLSP.
- Нет поля в totals → 0. Входы `dob*` / `nvih` / `teh` / `medk` / `prem*` / `visl*` / `tek_nr` / `kl*` заполняются (шаг A).

### 4.8. Шаг 7 — закрытие месяца и выгрузка

- `arm_periods` (`open` / `closed`). Закрытый месяц: нельзя назначать, снимать, править часы.
- Открыть снова может нарядчик или админ.
- `/uchet/lsbuh.dbf?month=YYYY-MM` — бинарный `LSBUH.DBF` CP866 как `PODG_LSH`; CSV `/uchet/lsbuh.csv` остаётся.
- `/uchet/reports` — затраты времени и отвлечения за месяц.

### 4.9. Патч живого FoxPro (отдельная ветка, не Laravel)

Чтобы нарядчик мог уйти с дискет, пока клон не готов:

- ввод-вывод A:/B: уведён на USB **F:**;
- сняты `isdiskin`, номер тома, spanning `DISKSPACE`;
- `SET DEVELOPMENT ON`, мешающие FXP/SPX удалены;
- бухгалтерии по-прежнему нужен шаблон `Lsbuh` на флешке.

Это **не** замена клона и в паритет Laravel не входит.

### 4.10. Тесты клона (зелёные на момент снимка)

| Файл | Что кроет |
|---|---|
| `tests/Feature/ArmImportAndPlanningHoursTest.php` | импорт + часы из разбивки |
| `tests/Feature/ArmPersonnelImportTest.php` | LKM/NAZN/OTVM, пароль, dispatcher |
| `tests/Feature/ArmPlanirRulesTest.php` | блок assign: формула интервала, NRCHAS, kolkint, экипаж, продолжение ночи, PRAZD1 |
| `tests/Feature/ArmAccountingTest.php` | 2 лицо, `/uchet`, закрытие, CSV |
| `tests/Unit/Arm/FoxTimeTest.php` | `чч.мм` |
| `tests/Unit/Arm/FormulaInterpreterTest.php` | IIF |
| `tests/Unit/Arm/ShiftHoursServiceTest.php` | подстановка часов |
| `tests/Unit/Arm/DbfReaderTest.php` | CP866 DBF |
| `tests/Feature/ArmFormulaInputsTest.php` | импорт NRCHAS/PREM/VISL/TEHUCH/SPPREM, totals, `/uchet/extras` |
| `tests/Unit/Arm/LsTotalsCalculatorTest.php` | `vc1`, `nvih` из дней × `dnnr`, отвлечение без смены не в `vchas1` |
| `tests/Feature/ArmGoldenMonthTest.php` | импорт NARJAD/`NR*`, эталон 2018-11 `VCHAS1` против дампа |
| `tests/Feature/ArmStepCTest.php` | два ЛС при смене должности, повторный `kind`, МЛ `registered`, архив УК |
| `tests/Unit/Arm/UnderworkAllocatorTest.php` | разнос `dob*` как LENA |
| `tests/Feature/ArmStepDTest.php` | справки DOKMENU: mtrzat, netrud, ночные 3+/4+, PRIM1, per1, перерыв 12 дней |
| `tests/Feature/ArmStepETest.php` | `/nsi`, импорт KODIF/ELKODIF, `5+` в ночных |
| `tests/Feature/ArmStepGTest.php` | `LSBUH.DBF` CP866, TABN_OLD/TABNOM1, чужое депо, artisan |
| `tests/Feature/ArmStepBTest.php` | `CHTAR` при пустом TARIF, `009` без VROTR, `KN` только 3+/4+ |
| `tests/Unit/Arm/NaryadPrintServiceTest.php` | pecnar: пара/вакант, выписка, RASST `27018`/`27017`, имя 07, PODPISI, TEXTN |
| `tests/Feature/ArmNaryadPrintTest.php` | `/naryad/print` лист с составом и подписями |

После правок доступа/маршрутов дополнительно: `JournalAccessTest`, `NaryadPlanningTest`, `WebRoutesSplitTest`, `UserRoleAccessTest`.

Булевы флаги (`users.is_active`, бригадир/помощник) **кастить в boolean**, иначе SQLite хранит `1` и `assertTrue` падает.

---

## 5. Очередь до 100% — идти строго по порядку

Не начинать шаг N+1, пока N не даёт проверяемый результат. Иначе снова размажется.

### Шаг A — входы формул — **сделано 2026-10-02**

Импорт `NRCHAS`/`PREM`/`VISL`/`TEHUCH`/`SPPREM`/`ELKODIF` 41 и 50. `LsTotalsCalculator` заполняет `dob*`/`nvih`/`teh`/`medk`/`prem*`/`visl*`/`tek_nr`/`kl*`/`per1`/`brig*` как `RAS1LS`. Оператор: `/uchet/extras` (техучёба, медк., выходной, % премии). `FPROC` → `percent_source` строки ЛС.

Не в этом шаге: два ЛС при смене должности в середине месяца (шаг C); сверка копеек с живым `LSH` (шаг B).

### Шаг B — золотой месяц — **эталон зафиксирован 2026-10-02, паритет не полный**

Дамп `~/Downloads/ARMNRD/Datanrd` содержит живые `NARJAD.DBF` / `LSH.DBF` / `LSBUH.DBF` и архивы `NR{мм}{гг}` / `C2{мм}{гг}` / `LS{мм}{гг}` (LSARH). `NR1118.DBF` пустой: ноябрь 2018 остался в живом `NARJAD` (2018-11…2020-10). `NRCHAS` в дампе с 02.2021.

**Эталон**

| | |
|---|---|
| Месяц 1 | **2018-11** — `NARJAD` + `LSH` + `LSBUH` |
| Месяц 2 | **2018-12** — `NARJAD` + `LSH` (массового `LSBUH` нет) |
| Табельные | **0321** МШ, **0428** МШ 1 кл. бригадир, **2575** П/М |
| Команда | `php artisan arm:golden-month 2018-11 --tabs=0321,0428,2575` |
| Импорт УК | `php artisan arm:import-dbf --only=naryad --month=2018-11 --tabs=0321,0428,2575` |

В текущем `LKM` нет 0321 и 0428 (ушли). Команда ставит карточку из `LSH`/`TEHUCH`. Норма месяца без `NRCHAS` 2018 берётся из `LSH.TEK_NR` (151.2 / 26 дн.).

**Сходится (порог 0.015)**

- `NARJAD.LIN1` при `!EMPTY(SMENA)` = `LSH.VCHAS1` = клон `hours_line` / `totals.vchas1` на всех трёх табельных.
- `DNI`, `VYH`, `VC1` с линией.
- Импорт `NR*.DBF` + живой `NARJAD` (пути на macOS через `realpath`, день не суммируется дважды).

**Расхождения**

| Таб | Срез | Поле | FoxPro | Клон | Причина |
|---|---|---|---|---|---|
| 0428, 2575 | NARJAD↔LSH | `NOCH1` / `VECH1` | LSH ниже/иначе | SUM `NARJAD` как `RAS1LS` | Клон = исходник `SUM … FOR !EMPTY(smena)`. Живой `NARJAD` после закрытия ЛС, видимо, правили. Клон не подгонять под LSH. |
| 2575 | LSH↔клон | `KN` | 6 | 7 | **Закрыто 2026-10-12:** `KN` как `RAS1LS` — только `NR_nsm1`/`NR_nsm2` (`3+`/`4+`). `5+` остаётся в PLANIR. |
| все | LSH↔клон | `VISL` | 112 / 151.2 | 0 | Нет `LKM.DTVISL` у 0321/0428; `SPPREM`/`VISL` на дату ЛС не восстановить из текущего дампа. |
| все | LSH↔клон | `PREMM` | 24 / −21 | 0 | В `SPPREM.DBF` нет строк `201811`. `RAS1LS` без SPPREM оставляет 0. |
| МШ | LSBUH | `077` | visl | нет строки | Нет `visl` в totals (тот же пробел LKM 2018). |
| МШ | LSBUH | `078` `СМН` `ВЫХ` | tarst 9.302 / 1, % 100 | tarst 0 | **Закрыто 2026-10-12:** пустой `TARIF` → `CHTAR`, `PROCNT=100`. Живой чай `odna_noch=SUMCHAI/KN` не пересчитывается: в дампе `FLSM.CHTAR` 078 сейчас 18.26, в LSBUH 2018 было 9.302. |
| все | LSBUH | `009` | VROTR=0 | 1 | **Закрыто 2026-10-12:** без тарифа и без `CHTAR` строка в ЛС/`LSBUH` не пишется (`sc_fls`). |
| 2575 | LSBUH | 004 и др. | FLSM | раньше пусто | Закрыто: `PODG_LSH` гоняет **и FLSM, и FLSP**. Клон теперь тоже. |

`VCHAS1` в ЛС больше не включает часы отвлечений без смены (`ОТ`/`Б` с `CHAS` и пустым `SMENA`) — как `RAS1LS`.

Шаг B **не закрывать**: `NOCH1` (клон не подгонять под LSH, если правили архив), `VISL`/`PREMM` (нужны LKM+SPPREM на 2018), живой пересчёт чая `078` (`SUMCHAI`/`KN`). `KN`, `CHTAR` справочных строк и `009` без тарифа закрыты 2026-10-12.

### Шаг C — повторный ЛС и маршрутные листы — **сделано 2026-10-05**

- Повторный ЛС: `arm_accounts.kind=repeat` (`LSHPOVT`), `ls_number=1` (`NOMLS=1`). `POST /uchet/generate` с `kind=repeat` не затирает первичные. Экран `/uchet/accounts?kind=repeat`.
- Смена должности не 1-го числа: два ЛС `ls_number` 0 и 1, периоды как `podgls`. Разнос `dob*` — `UnderworkAllocator` как `LENA.PRG` / `RAS1LS.prov_dobav`.
- Маршрутные листы: `/uchet/sheets`, `naryad_assignments.registered` ← `OTBOR`.
- Копии УК: `arm_uk_copies` при закрытии месяца (`KOP1`/`KOP2`). `/uchet/archive`.
- Уникальность ЛС: `(user_id, year_month, kind, ls_number)`.

Не в этом шаге: полный BROW часов МЛ как учётная карточка (часы правятся на карточке человека); чай/булочки `NR_schai`; выгрузка `LSBUHP.DBF`.

### Шаг D — отчёты `DOKMENU` — **сделано 2026-10-05**

`DokmenuReportService`, `/uchet/reports` + `/uchet/reports/{kind}`. Печать HTML из тех же таблиц.

- печать нарядов / выписка — `NaryadPrintService` (составы RASST, TEXTN, PODPISI — 2026-10-12);
- затраты рабочего времени — сетка дней × код (`mtrzat`) и расширенные часы УК;
- отчёт о работе машинистов — на лету, без `OTV1`;
- нетрудоспособность — дни `Б`/`Т` (`netrud`);
- справки: пометки У/К, бригадиры, классность, список, ночные `3+`/`4+`, PRIM1, переработка `per1`, перерывы ≥12 записей без смены, праздничные часы, назначения за период.

Импорт: `RAZBSM.PRIM1` → `extra_mark`, `NARJAD.OTBOR` → `mark_code` + `registered`.

Не в этом шаге: бланки «приказ», годовой `OTV_MES`, пункт «текущая численность» (в FoxPro выключен). RASST/TEXTN/PODPISI на печати наряда закрыты 2026-10-12.

### Шаг E — экраны НСИ (`NSIMENU`) — **сделано 2026-10-05**

`NsiController`, `/nsi`. Импорт всех `KODIF`/`ELKODIF` (не только 41 и 50), `PODPISI`, `RASST`/`RASST1`. CRUD кодификаторов; 41/50 пишут `arm_tariffs`/`arm_pay_kinds`; 52 создаёт `deviations_catalog` только для нового `short_code`.

Справочники меню ведут на существующие экраны нарядчика (графики, разбивки, праздники, календарь, экипажи т6/т5) и на kodspr 03/05/06/51. Нормативы: NRCHAS, PREM, VISL, тарифы, отбор FLSM/FLSP, расстановка. Настройка АРМ: пользователи портала (список), NRDPAR+аванс в `naryad_norms.planir` (слияние, `5+` всегда в ночных), названия KODIF.

Не в этом шаге: печать DOS, перекрёстные ссылки `COMKOD`, порядок полей.

### Шаг F — остаток `PLANIR` — **сделано 2026-10-02**

Правила не мягче FoxPro (ужесточать можно).

- формула интервала `часы×2` при `NR_12` не `+` (главное послабление, которое закрыли);
- `kolkint`, NRCHAS-потолок, пара со стажем &lt; 1 года при `line_mode=2`, дубль при `line_mode=1`;
- праздничные часы `gath_ch` в `naryad_assignments.hours_holiday*`;
- импорт `NRDPAR` → `naryad_norms.planir` (merge; раннее окно 0,0 не выключает `ran3`);
- отчёт полноты `/naryad/partial/control` (F6 / Shift+F5);
- автопродолжение с ночи валидируется.

Не в этом шаге: ввод готового наряда (`VVODNAR`) — в Печатниках `NR_linia=1`, пункт выключен. Категории МШ vs `RAZBSM.prim1` (Jauza) — отчёт, не блок; можно добавить в контроль позже.

### Шаг G — формат для бухгалтерии — **код 2026-10-07, 1С не подтверждала**

Бинарный dBASE III CP866 как дамп `LSBUH.DBF` / `PODG_LSH`: поля `GODMES,TABN,VOPL,TARST,PROCNT,VROTR,ZAKAZ,PROF,OTBOR,NOMLS,DDE,OSN_PROF,TABN_OLD,DEPO,DEPOKOM`.

- `/uchet/lsbuh.dbf?month=YYYY-MM` (имя файла `LSBUH.DBF`), `kind=primary|repeat`;
- CSV `/uchet/lsbuh.csv` те же строки;
- `php artisan arm:export-lsbuh YYYY-MM [path]`;
- после строк: `TABN_OLD` = табельный, `TABN` ← `LKM.TABNOM1` если заполнен, `DEPO` если не своё (`own_depo` по умолчанию `ТЧ15` = COMKOD 0101), `NOMLS+5` при TABNOM1 или чужом депо, `DDE='1'` только при `NR_dde='+'` (у Печатников пусто).
- `OSN_PROF` — `ELKODIF` kodspr=05 `NOM` по должности.

Не в этом шаге: нарезка `DepoXX.dbf` / `DEPOKOM`, копия на `F:\BUH`, `LSBUHP.DBF`.

Шаг **не закрывать как принятый бухгалтерией**, пока 1С не съест живой файл. Паритет B не объявлять.

---

## 6. Карта меню FoxPro → клон

Главное меню `ARMMENU` (пункты 6 и 7 не клонировать).

| FoxPro | Сейчас | Дальше |
|---|---|---|
| 1. НСИ | `/nsi` + экраны нарядчика | DOS-печать / COMKOD |
| 2. Картотека | список + назначения + отвлечения + POZEL | полный редактор LKM, отбор, архив ЛС |
| 3. Планирование и учёт | `/naryad` + `/uchet` | повторный ЛС / МЛ / архив УК — шаг C |
| 4. Отчёты и справки | `/uchet/reports` + печать наряда (RASST/TEXTN/PODPISI) | бланки приказа / `OTV_MES` |
| 5. Технические функции | не нужно 1:1 (дискеты, индекс, отказы) | бэкап Postgres / MinIO |
| 0. Конец работы | выход из портала | — |

`UCHMENU`: планирование ✓, учётные карточки ✓, лицевые счета ✓ (первичные и повторные), маршрутные листы ✓, доп. выплаты ✓ (`/uchet/extras`), архив УК ✓. `DOKMENU` ✓ (`/uchet/reports`).

`KARTMENU`: ведение картотеки частично (нет полного LKM), назначения ✓, отвлечения ✓, подстройки ✓ (день + месяц раздельно), отбор ✗, архив ЛС ✗.

---

## 7. Правила, которые нельзя терять

1. Клон растёт **в этом** Laravel-проекте. Новые таблицы учёта — только через `armd.md`.
2. Нарядчик = `dispatcher`. Оператор учёта = `operator`. Не путать с инструктором и водителем.
3. Часы 2 лица — колонки той же клетки, не вторая строка сетки.
4. Формулы `FLSM`/`FLSP` исполнять интерпретатором, не зашивать ~55 `IIF`.
5. Время смены — `чч.мм` (`FoxTime`), не десятичные часы.
6. Календарь бесконечный; закрытие месяца **отдельно** от «открыть месяц». Открытие месяца не удаляет старые даты.
7. Сетка — один календарный месяц, левые колонки заморожены, горизонтальный скролл. Два месяца в одной сетке не нужны.
8. Имена маршрутов `naryad.*` не менять.
9. Redis / `FOR UPDATE` вместо `FLOCK` и `trab.dbf`.
10. Файлы в браузер — через `FileProxyService`, не `Storage::temporaryUrl()`.
11. Повторный импорт DBF не перетирает пароль и не понижает нарядчика.
12. `armd.md` — схемы. `armd-progress.md` — статус и очередь. Оба файла обновлять в одном PR/шаге.

---

## 8. Команды и точки входа

```bash
php artisan arm:import-dbf
php artisan arm:import-dbf --only=graphs,breakdowns,holidays,calendar
php artisan arm:import-dbf --only=personnel,appointments,absences,adjustments
php artisan arm:import-dbf --only=formulas,chas2
php artisan arm:import-dbf --only=nrchas,nrdpar,prem,visl,extras,spprem,tariffs
php artisan arm:import-dbf --only=codes,signatures,rosters
php artisan arm:import-dbf --only=naryad --month=2018-11 --tabs=0321,0428,2575
php artisan arm:golden-month 2018-11 --tabs=0321,0428,2575
```

Путь по умолчанию: `config('arm.dbf_path')` / `ARM_DBF_PATH`, иначе `~/Downloads/ARMNRD/Datanrd`.

| URL | Кто | Зачем |
|---|---|---|
| `/naryad` | dispatcher | сетка, справочники, картотека |
| `/nsi` | dispatcher | NSIMENU: кодификаторы, нормативы, NRDPAR |
| `/naryad/partial/control` | dispatcher | полнота сетки (F6) |
| `/uchet` | operator + dispatcher + admin | карточки |
| `/uchet/accounts` | то же | лицевые счета |
| `/uchet/lsbuh.dbf?month=YYYY-MM` | то же | `LSBUH.DBF` CP866 |
| `/uchet/lsbuh.csv?month=YYYY-MM` | то же | тот же состав, CSV |
| `/uchet/extras` | то же | доплаты TEHUCH и премия SPPREM |
| `/uchet/sheets` | то же | регистрация маршрутных листов |
| `/uchet/archive` | то же | копии УК после закрытия ЛС |
| `/uchet/reports` | то же | меню DOKMENU |
| `/uchet/reports/{kind}` | то же | справка (hours, time-costs, work, disability, marks, …) |
| `POST /uchet/generate` | то же | собрать ЛС (`kind=primary\|repeat`) |
| `POST /uchet/close` / `reopen` | close — учёт; reopen — dispatcher/admin | период + снимок УК |

Ключевые классы: `PlanirRulesService`, `PlanirControlService`, `ShiftHoursService`, `FoxTime`, `PersonnelImporter`, `AccountingImporter`, `FormulaInterpreter`, `LsTotalsCalculator`, `AccountBuilder`, `UnderworkAllocator`, `UkCopyService`, `DokmenuReportService`, `NaryadPrintService`, `LsbuhExportService`, `DbfWriter`, `GoldenMonthComparer`, `UchetController`, `SetkaController`, `NsiController`.

---

## 9. Что делать в следующей сессии

1. Прочитать этот файл и `armd.md` (не начинать с «набросать бухгалтерию»).
2. Эталон шага B зафиксирован (2018-11, таб. 0321/0428/2575). Дальше по B: выслуга/премия с живого LKM+SPPREM, тарифы строк `078`/`СМН`/`ВЫХ`, решить `NOCH1` (архив vs `SUM NARJAD`). Пока B не зелёный целиком — не объявлять паритет.
3. Шаг G: бинарный `LSBUH.DBF` в коде. Не закрывать G как «1С приняла» без живой выгрузки. Не объявлять паритет B.
4. Остаток B: `NOCH1` (не подгонять под LSH), `VISL`/`PREMM` с LKM+SPPREM 2018, живой чай `078` (`SUMCHAI`/`KN`). `KN`/`CHTAR`/`009` закрыты.
5. Печать наряда: RASST/TEXTN/PODPISI на листе есть. Чтобы получить бланк как `11_utf8.txt`, на дату нужны график в календаре, разбивка, назначения в сетке и расстановка составов `/nsi/rosters`. Диалог «кого назначить машинистом» не делали.

Если контекст сессии снова оборвётся: направление = этот файл, схемы = `armd.md`, эталон = `ARMNRD`.

---

## 10. Журнал снимков

| Дата | Что зафиксировано |
|---|---|
| 2026-09-30 | Шаги 1–7 контура сделаны. 100% нет. Следующий код — шаг A (входы формул). |
| 2026-09-30 | Печать нарядов / выписка: `NaryadPrintService` по `pecnar`. RASST ещё нет. |
| 2026-09-30 | FoxPro: печать нарядов за диапазон дат → `DOC\ДД.ММ.ГГ.txt` (выписка `…v.txt`). |
| 2026-10-02 | Документация сверена с кодом: печать в `AGENTS.md` / `armd.md`, поиск ФИО `/naryady/search-people`, `TECHNICAL_DEBT.md`. |
| 2026-10-02 | Шаг A: входы формул `RAS1LS` (`NRCHAS`/`PREM`/`VISL`/`TEHUCH`/`SPPREM`/тарифы), `/uchet/extras`, `LsTotalsCalculator`. |
| 2026-10-02 | Шаг F: PLANIR доведён (формула интервала, NRCHAS, kolkint, экипаж, PRAZD1, контроль сетки, NRDPAR). Эталонный месяц — шаг B, не раньше. |
| 2026-10-02 | Шаг B: эталон 2018-11 таб. 0321/0428/2575. Импорт `NARJAD`/`NR*`, `arm:golden-month`. `VCHAS1`/`DNI`/`VYH` сходятся. Ночь LSH≠NARJAD, visl/premm без карточки 2018. |
| 2026-10-05 | Шаг C: два ЛС при смене должности + `LENA`, повторный ЛС `kind=repeat`, `/uchet/sheets`, архив УК `arm_uk_copies` при закрытии. |
| 2026-10-05 | Шаг D: справки DOKMENU на `/uchet/reports/{kind}`, `PRIM1`/`OTBOR`, ночные только 3+/4+. |
| 2026-10-05 | Шаг E: `/nsi` кодификаторы KODIF/ELKODIF, RASST, PODPISI, нормативы, NRDPAR/аванс. |
| 2026-10-07 | Шаг G: `LSBUH.DBF` CP866 (`LsbuhExportService` / `arm:export-lsbuh`). Живая 1С не подтверждала. |
| 2026-10-12 | Шаг B: `KN` как RAS1LS (3+/4+), `CHTAR` при пустом TARIF, `009` без VROTR. Паритет не полный (`NOCH1`, VISL/PREMM, чай 078). |
| 2026-10-12 | Печать наряда: RASST/RASST1 + kodspr 03/07, TEXTN, PODPISI, флаги `NR_PVAG`/`NR_VERT` в `planir.print`. Образец `11_utf8.txt`. |
