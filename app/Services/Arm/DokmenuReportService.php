<?php

namespace App\Services\Arm;

use App\Models\ArmAbsence;
use App\Models\ArmAccount;
use App\Models\ArmAppointment;
use App\Models\ArmHoliday;
use App\Models\ArmMonthNorm;
use App\Models\ArmPersonnel;
use App\Models\ArmShiftBreakdown;
use App\Models\NaryadAssignment;
use App\Models\NaryadNorm;
use App\Models\NaryadQuota;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Отчёты и справки DOKMENU.PRG (Печатники).
 *
 * Печать нарядов / выписка — {@see NaryadPrintService}.
 * Коды COMKOD дампа Печатников: выходной Е, больничный Б, травма Т, совместители 11.
 * Ночные в этой справке — только NR_nsm1/NR_nsm2 (`3+`/`4+`); `5+` остаётся ужесточением PLANIR.
 */
class DokmenuReportService
{
    public const KIND_HOURS = 'hours';

    public const KIND_TIME_COSTS = 'time-costs';

    public const KIND_DISABILITY = 'disability';

    public const KIND_WORK = 'work';

    public const KIND_MARKS = 'marks';

    public const KIND_BRIGADIERS = 'brigadiers';

    public const KIND_CLASSES = 'classes';

    public const KIND_PERSONNEL = 'personnel';

    public const KIND_NIGHTS = 'nights';

    public const KIND_EXTRA_MARKS = 'extra-marks';

    public const KIND_OVERTIME = 'overtime';

    public const KIND_BREAKS = 'breaks';

    public const KIND_HOLIDAYS = 'holidays';

    public const KIND_APPOINTMENTS = 'appointments';

    private const MONTHS = [
        1 => 'январь', 2 => 'февраль', 3 => 'март', 4 => 'апрель',
        5 => 'май', 6 => 'июнь', 7 => 'июль', 8 => 'август',
        9 => 'сентябрь', 10 => 'октябрь', 11 => 'ноябрь', 12 => 'декабрь',
    ];

    public function __construct(
        private PlanirRulesService $planir,
        private ShiftHoursService $shiftHours,
    ) {}

    /**
     * @return list<array{kind: string, group: string, title: string, needs: list<string>}>
     */
    public function catalog(): array
    {
        return [
            ['kind' => self::KIND_HOURS, 'group' => 'naryad', 'title' => 'Часы по учётным карточкам', 'needs' => ['month']],
            ['kind' => self::KIND_TIME_COSTS, 'group' => 'naryad', 'title' => 'Затраты рабочего времени', 'needs' => ['month']],
            ['kind' => self::KIND_WORK, 'group' => 'naryad', 'title' => 'Отчёт о работе машинистов', 'needs' => ['month']],
            ['kind' => self::KIND_DISABILITY, 'group' => 'naryad', 'title' => 'Отчёт о нетрудоспособности', 'needs' => ['from_month', 'to_month']],
            ['kind' => self::KIND_MARKS, 'group' => 'sprav', 'title' => 'Особые пометки У/К', 'needs' => ['from', 'to']],
            ['kind' => self::KIND_BRIGADIERS, 'group' => 'sprav', 'title' => 'Список бригадиров', 'needs' => ['as_of']],
            ['kind' => self::KIND_CLASSES, 'group' => 'sprav', 'title' => 'Классность машинистов', 'needs' => ['as_of']],
            ['kind' => self::KIND_PERSONNEL, 'group' => 'sprav', 'title' => 'Список машинистов', 'needs' => ['as_of']],
            ['kind' => self::KIND_NIGHTS, 'group' => 'sprav', 'title' => 'Отработанные ночные смены', 'needs' => ['month']],
            ['kind' => self::KIND_EXTRA_MARKS, 'group' => 'sprav', 'title' => 'Внутрисменные отвлечения', 'needs' => ['month', 'mark']],
            ['kind' => self::KIND_OVERTIME, 'group' => 'sprav', 'title' => 'Справка о переработке', 'needs' => ['month']],
            ['kind' => self::KIND_BREAKS, 'group' => 'sprav', 'title' => 'Перерывы в работе', 'needs' => ['date']],
            ['kind' => self::KIND_HOLIDAYS, 'group' => 'sprav', 'title' => 'Праздничные часы', 'needs' => ['from', 'to']],
            ['kind' => self::KIND_APPOINTMENTS, 'group' => 'sprav', 'title' => 'Назначения за период', 'needs' => ['from', 'to', 'user_id']],
        ];
    }

    public function title(string $kind): string
    {
        foreach ($this->catalog() as $item) {
            if ($item['kind'] === $kind) {
                return $item['title'];
            }
        }

        return 'Отчёт';
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function build(string $kind, array $params = []): array
    {
        return match ($kind) {
            self::KIND_HOURS => $this->hours($this->month($params)),
            self::KIND_TIME_COSTS => $this->timeCosts($this->month($params)),
            self::KIND_WORK => $this->machinistWork($this->month($params)),
            self::KIND_DISABILITY => $this->disability($this->month($params, 'from_month'), $this->month($params, 'to_month')),
            self::KIND_MARKS => $this->specialMarks($this->day($params, 'from'), $this->day($params, 'to'), (string) ($params['mark'] ?? '')),
            self::KIND_BRIGADIERS => $this->brigadiers($this->day($params, 'as_of')),
            self::KIND_CLASSES => $this->classCounts($this->day($params, 'as_of')),
            self::KIND_PERSONNEL => $this->personnelList($this->day($params, 'as_of'), $params),
            self::KIND_NIGHTS => $this->nightShifts($this->month($params)),
            self::KIND_EXTRA_MARKS => $this->extraMarks(
                $this->month($params),
                (string) ($params['mark'] ?? ''),
                (string) ($params['extra_mode'] ?? 'all')
            ),
            self::KIND_OVERTIME => $this->overtime($this->month($params)),
            self::KIND_BREAKS => $this->workBreaks($this->day($params, 'date')),
            self::KIND_HOLIDAYS => $this->holidayHours($this->day($params, 'from'), $this->day($params, 'to')),
            self::KIND_APPOINTMENTS => $this->appointments(
                (int) ($params['user_id'] ?? 0),
                $this->day($params, 'from'),
                $this->day($params, 'to')
            ),
            default => [
                'kind' => $kind,
                'layout' => 'table',
                'title' => 'Отчёт',
                'message' => 'Нет такого отчёта.',
                'columns' => [],
                'rows' => [],
            ],
        };
    }

    /**
     * Часы УК за месяц (расширение текущего /uchet/reports).
     *
     * @return array<string, mixed>
     */
    public function hours(string $yearMonth): array
    {
        [$start, $end] = $this->monthBounds($yearMonth);
        $rows = NaryadAssignment::query()
            ->with('user.personnel')
            ->whereBetween('plan_date', [$start->toDateString(), $end->toDateString()])
            ->get()
            ->groupBy('user_id')
            ->map(function (Collection $days) {
                $user = $days->first()?->user;
                $person = $user?->personnel;

                return [
                    'tab' => $person?->tab_number,
                    'name' => $person?->full_name ?: $user?->name,
                    'line' => $this->roundSum($days, 'hours_line'),
                    'reserve' => $this->roundSum($days, 'hours_reserve'),
                    'line_2' => $this->roundSum($days, 'hours_line_2'),
                    'night' => round($days->sum(fn ($r) => (float) $r->hours_night + (float) $r->hours_night_2), 2),
                    'evening' => round($days->sum(fn ($r) => (float) $r->hours_evening + (float) $r->hours_evening_2), 2),
                    'holiday' => round($days->sum(fn ($r) => (float) $r->hours_holiday + (float) $r->hours_holiday_reserve), 2),
                    'holiday_2' => round($days->sum(fn ($r) => (float) $r->hours_holiday_2 + (float) $r->hours_holiday_2_reserve), 2),
                    'overtime' => $this->roundSum($days, 'hours_overtime'),
                ];
            })
            ->sortBy('tab')
            ->values()
            ->all();

        $absences = ArmAbsence::query()
            ->with('user.personnel')
            ->whereDate('starts_on', '<=', $end->toDateString())
            ->whereDate('ends_on', '>=', $start->toDateString())
            ->orderBy('starts_on')
            ->get()
            ->map(fn (ArmAbsence $a) => [
                'tab' => $a->tab_number ?: $a->user?->personnel?->tab_number,
                'name' => $a->user?->personnel?->full_name ?: $a->user?->name,
                'kind' => $a->kind_code,
                'from' => $a->starts_on?->format('d.m.Y'),
                'to' => $a->ends_on?->format('d.m.Y'),
            ])
            ->all();

        return [
            'kind' => self::KIND_HOURS,
            'layout' => 'hours',
            'title' => 'Часы по учётным карточкам за '.$this->monthTitle($yearMonth),
            'columns' => ['Таб.', 'ФИО', 'Линия', 'Резерв', '2 лицо', 'Ночь', 'Вечер', 'Праздник', 'Празд. 2 л.', 'Перераб.'],
            'rows' => $rows,
            'absences' => $absences,
            'message' => $rows === [] ? 'Нет нарядов за месяц.' : null,
        ];
    }

    /**
     * mtrzat: численность МШ/П/М по дням и коду работы/отвлечения.
     *
     * @return array<string, mixed>
     */
    public function timeCosts(string $yearMonth): array
    {
        [$start, $end] = $this->monthBounds($yearMonth);
        $daysInMonth = $start->daysInMonth;
        $holidays = $this->holidaySet($start, $end);
        $kodvih = $this->kodvih();
        $assignments = $this->assignmentsBetween($start, $end);
        $people = $this->personnelByUserId();
        $positions = $this->positionsIndex($assignments->pluck('user_id')->unique()->all(), $end);

        $codes = $assignments
            ->filter(fn (NaryadAssignment $row) => ! $this->filled($row->shift_code))
            ->map(fn (NaryadAssignment $row) => $this->workCode($row))
            ->filter(fn (string $code) => $code !== '' && ! $this->isLineRoute($code))
            ->unique()
            ->sort()
            ->values()
            ->all();
        if (! in_array($kodvih, $codes, true)) {
            $codes[] = $kodvih;
        }

        $blocks = [];
        foreach ($codes as $code) {
            $blocks[] = $this->emptyDayBlock($code, $daysInMonth);
        }
        $blocks[] = $this->emptyDayBlock('ЛИН', $daysInMonth);
        $index = [];
        foreach ($blocks as $i => $block) {
            $index[$block['code']] = $i;
        }

        foreach ($assignments as $row) {
            $date = $row->plan_date?->toDateString();
            if ($date === null) {
                continue;
            }
            $day = (int) $row->plan_date->format('j');
            $pos = $this->positionOn($row->user_id, $row->plan_date, $people[$row->user_id] ?? null, $positions);
            $bucket = $this->mashPomBucket($pos);
            if ($bucket === null) {
                continue;
            }
            $sunday = $row->plan_date->dayOfWeek === Carbon::SUNDAY;
            $holiday = isset($holidays[$date]);
            $work = $this->workCode($row);
            $hasShift = $this->filled($row->shift_code);

            if ($hasShift) {
                $blocks[$index['ЛИН']][$bucket][$day]++;
                $blocks[$index['ЛИН']][$bucket.'_total']++;
            }

            foreach ($codes as $code) {
                $weekdayHit = $work === $code && ! $sunday && ! $holiday;
                $restHit = ($sunday || $holiday) && ! $hasShift && $code === $kodvih;
                if (! $weekdayHit && ! $restHit) {
                    continue;
                }
                $blocks[$index[$code]][$bucket][$day]++;
                $blocks[$index[$code]][$bucket.'_total']++;
            }
        }

        $blocks = array_values(array_filter($blocks, function (array $block) use ($kodvih) {
            return $block['code'] === 'ЛИН'
                || $block['code'] === $kodvih
                || $block['mash_total'] > 0
                || $block['pom_total'] > 0;
        }));

        return [
            'kind' => self::KIND_TIME_COSTS,
            'layout' => 'matrix',
            'title' => 'Справка о затратах рабочего времени машинистов и помощников за '.$this->monthTitle($yearMonth),
            'days' => $daysInMonth,
            'blocks' => $blocks,
            'message' => $blocks === [] ? 'Нет данных за месяц.' : null,
        ];
    }

    /**
     * netrud: дни Б/Т по месяцам, без воскресений и PRAZD.
     *
     * @return array<string, mixed>
     */
    public function disability(string $fromMonth, string $toMonth): array
    {
        $from = Carbon::createFromFormat('Y-m', $fromMonth)->startOfMonth();
        $to = Carbon::createFromFormat('Y-m', $toMonth)->endOfMonth();
        if ($to->lt($from)) {
            [$from, $to] = [$to->copy()->startOfMonth(), $from->copy()->endOfMonth()];
        }
        $sick = $this->kodbol();
        $injury = $this->kodtrav();
        $holidays = $this->holidaySet($from, $to);
        $people = $this->personnelByUserId();
        $byUser = [];

        $assignments = $this->assignmentsBetween($from, $to);
        foreach ($assignments as $row) {
            $work = $this->workCode($row);
            if ($work !== $sick && $work !== $injury) {
                continue;
            }
            if ($row->plan_date?->dayOfWeek === Carbon::SUNDAY) {
                continue;
            }
            $date = $row->plan_date?->toDateString();
            if ($date === null || isset($holidays[$date])) {
                continue;
            }
            $month = (int) $row->plan_date->format('n');
            $userId = (int) $row->user_id;
            if (! isset($byUser[$userId])) {
                $person = $people[$userId] ?? null;
                $byUser[$userId] = [
                    'tab' => $person?->tab_number,
                    'name' => $person?->full_name ?: $row->user?->name,
                    'sick' => array_fill(1, 12, 0),
                    'injury' => array_fill(1, 12, 0),
                ];
            }
            if ($work === $sick) {
                $byUser[$userId]['sick'][$month]++;
            } else {
                $byUser[$userId]['injury'][$month]++;
            }
        }

        $rows = [];
        $totalSick = 0;
        $totalInjury = 0;
        foreach (collect($byUser)->sortBy('tab') as $row) {
            $sickSum = array_sum($row['sick']);
            $injurySum = array_sum($row['injury']);
            $totalSick += $sickSum;
            $totalInjury += $injurySum;
            $rows[] = [
                'tab' => $row['tab'],
                'name' => $row['name'],
                'sick' => $row['sick'],
                'injury' => $row['injury'],
                'sick_total' => $sickSum,
                'injury_total' => $injurySum,
            ];
        }

        $year = $to->year;

        return [
            'kind' => self::KIND_DISABILITY,
            'layout' => 'disability',
            'title' => 'Справка о нетрудоспособности М и П/М за '.$year.' год.',
            'codes' => $sick.'/'.$injury,
            'rows' => $rows,
            'total_sick' => $totalSick,
            'total_injury' => $totalInjury,
            'message' => $rows === [] ? 'Нет дней нетрудоспособности за период.' : null,
        ];
    }

    /**
     * f_sprmes / prev_om без сохранения OTV1: дни, часы, переработка/недоработка.
     *
     * @return array<string, mixed>
     */
    public function machinistWork(string $yearMonth): array
    {
        [$start, $end] = $this->monthBounds($yearMonth);
        $holidays = $this->holidaySet($start, $end);
        $norm = ArmMonthNorm::query()->where('year_month', $yearMonth)->first();
        $monthHours = (float) ($norm?->month_hours ?? 0);
        $dayHours = (float) ($norm?->day_hours ?? 0);
        $assignments = $this->assignmentsBetween($start, $end)->groupBy('user_id');
        $people = $this->personnelByUserId();
        $rows = [];

        foreach ($assignments as $userId => $days) {
            $person = $people[$userId] ?? null;
            if ($person && ! $this->activeOn($person, $start, $end)) {
                continue;
            }
            $workDays = 0;
            $hours = 0.0;
            $allchas = 0.0;
            $holidayHours = 0.0;
            $absences = [];
            foreach ($days as $row) {
                $allchas += $this->allHours($row);
                $holidayHours += (float) $row->hours_holiday + (float) $row->hours_holiday_reserve
                    + (float) $row->hours_holiday_2 + (float) $row->hours_holiday_2_reserve;
                if ($this->filled($row->shift_code)) {
                    $workDays++;
                    $hours += $this->allHours($row);
                } else {
                    $code = $this->workCode($row);
                    if ($code !== '') {
                        $absences[$code] = ($absences[$code] ?? 0) + 1;
                    }
                }
            }
            $tek = $this->periodNorm($person, $start, $end, $monthHours, $dayHours, $holidays);
            $per = $allchas - $tek;
            $ned = $per < 0 ? -$per : 0.0;
            $per = $per - $holidayHours;
            if ($per <= 0.01) {
                $per = 0.0;
            }
            if ($ned <= 0.01) {
                $ned = 0.0;
            }
            $rows[] = [
                'tab' => $person?->tab_number,
                'name' => $person?->full_name,
                'position' => $person?->position_code,
                'work_days' => $workDays,
                'hours' => round($hours, 2),
                'overtime' => round($per, 2),
                'underwork' => round($ned, 2),
                'absences' => $absences,
            ];
        }

        usort($rows, fn ($a, $b) => strcmp((string) $a['tab'], (string) $b['tab']));
        $absenceCodes = collect($rows)->flatMap(fn ($r) => array_keys($r['absences']))->unique()->sort()->values()->all();

        return [
            'kind' => self::KIND_WORK,
            'layout' => 'work',
            'title' => 'Затраты рабочего времени работников за '.$this->monthTitle($yearMonth),
            'absence_codes' => $absenceCodes,
            'rows' => $rows,
            'message' => $rows === [] ? 'Нет данных за месяц.' : null,
        ];
    }

    /**
     * otm_uk: NARJAD.OTBOR / mark_code.
     *
     * @return array<string, mixed>
     */
    public function specialMarks(CarbonInterface $from, CarbonInterface $to, string $onlyMark = ''): array
    {
        $onlyMark = trim($onlyMark);
        $rows = NaryadAssignment::query()
            ->with(['user.personnel', 'breakdown'])
            ->whereBetween('plan_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('user_id')
            ->orderBy('plan_date')
            ->get()
            ->filter(function (NaryadAssignment $row) use ($onlyMark) {
                $mark = $this->markOf($row);
                if ($mark === '') {
                    return false;
                }

                return $onlyMark === '' || $mark === $onlyMark;
            });

        $groups = [];
        foreach ($rows as $row) {
            $person = $row->user?->personnel;
            $userId = (int) $row->user_id;
            if (! isset($groups[$userId])) {
                $groups[$userId] = [
                    'tab' => $person?->tab_number,
                    'name' => $person?->full_name ?: $row->user?->name,
                    'rows' => [],
                ];
            }
            $groups[$userId]['rows'][] = [
                'date' => $row->plan_date?->format('d.m.Y'),
                'mark' => $this->markOf($row),
                'work' => $this->workCode($row),
                'shift' => (string) $row->shift_code,
                'start' => FoxTime::format($row->breakdown?->start_hours),
                'end' => FoxTime::format($row->breakdown?->end_hours),
            ];
        }

        return [
            'kind' => self::KIND_MARKS,
            'layout' => 'grouped',
            'title' => 'Особые отметки в учётных карточках с '.$from->format('d.m.Y').' по '.$to->format('d.m.Y'),
            'groups' => array_values($groups),
            'message' => $groups === [] ? 'В указанном периоде отсутствуют особые отметки.' : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function brigadiers(CarbonInterface $asOf): array
    {
        $rows = [];
        $n = 0;
        foreach ($this->personnelOn($asOf, firedExclusive: true) as $person) {
            if (! $person->is_brigadier) {
                continue;
            }
            $n++;
            $rows[] = [
                'n' => $n,
                'tab' => $person->tab_number,
                'name' => $person->full_name,
                'position' => $person->position_code,
                'class' => $person->class_code,
            ];
        }

        return [
            'kind' => self::KIND_BRIGADIERS,
            'layout' => 'table',
            'title' => 'Список бригадиров на '.$asOf->format('d.m.Y'),
            'columns' => ['№', 'Таб.', 'Ф.И.О.', 'Должность', 'Класс'],
            'rows' => $rows,
            'keys' => ['n', 'tab', 'name', 'position', 'class'],
            'message' => $rows === [] ? 'Нет бригадиров на дату.' : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function classCounts(CarbonInterface $asOf): array
    {
        $shopCombined = $this->shopCombined();
        $people = $this->personnelOn($asOf, firedExclusive: true);
        $pairs = [
            ['МШ', '1'], ['П/М', '1'], ['МШ', '2'], ['МШ', '3'], ['МШ', '0'], ['П/М', '0'],
        ];
        $rows = [];
        foreach ($pairs as [$pos, $class]) {
            $main = 0;
            $sovm = 0;
            foreach ($people as $person) {
                if (trim((string) $person->position_code) !== $pos || trim((string) $person->class_code) !== $class) {
                    continue;
                }
                if ($this->isCombinedShop($person, $shopCombined)) {
                    $sovm++;
                } else {
                    $main++;
                }
            }
            $rows[] = ['position' => $pos, 'class' => $class, 'count' => $main, 'combined' => $sovm];
        }

        $sum = function (string $pos) use ($people, $shopCombined): array {
            $main = 0;
            $sovm = 0;
            foreach ($people as $person) {
                if (trim((string) $person->position_code) !== $pos) {
                    continue;
                }
                if ($this->isCombinedShop($person, $shopCombined)) {
                    $sovm++;
                } else {
                    $main++;
                }
            }

            return [$main, $sovm];
        };
        [$mash, $mashS] = $sum('МШ');
        [$pom, $pomS] = $sum('П/М');
        $allMain = 0;
        $allSovm = 0;
        foreach ($people as $person) {
            if ($this->isCombinedShop($person, $shopCombined)) {
                $allSovm++;
            } else {
                $allMain++;
            }
        }

        return [
            'kind' => self::KIND_CLASSES,
            'layout' => 'table',
            'title' => 'Классность машинистов на '.$asOf->format('d.m.Y'),
            'columns' => ['Должность', 'Класс', 'Количество', 'Совместители'],
            'rows' => $rows,
            'keys' => ['position', 'class', 'count', 'combined'],
            'footer' => [
                ['position' => 'ИТОГО МШ', 'class' => '', 'count' => $mash, 'combined' => $mashS],
                ['position' => 'ИТОГО П/М', 'class' => '', 'count' => $pom, 'combined' => $pomS],
                ['position' => 'ВСЕГО', 'class' => '', 'count' => $allMain, 'combined' => $allSovm],
            ],
            'message' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function personnelList(CarbonInterface $asOf, array $filters = []): array
    {
        $want = [];
        foreach (['1' => 'ms1', '2' => 'ms2', '3' => 'ms3', '0' => 'ms0'] as $class => $key) {
            if ($this->flag($filters, $key, true)) {
                $want[] = ['МШ', $class];
            }
        }
        if ($this->flag($filters, 'pm1', true)) {
            $want[] = ['П/М', '1'];
        }
        if ($this->flag($filters, 'pm0', true)) {
            $want[] = ['П/М', '0'];
        }
        $order = (string) ($filters['order'] ?? 'fio');
        $rows = [];
        foreach ($this->personnelOn($asOf, firedExclusive: false) as $person) {
            $pos = trim((string) $person->position_code);
            $class = trim((string) $person->class_code);
            $ok = false;
            foreach ($want as [$p, $c]) {
                if ($pos === $p && $class === $c) {
                    $ok = true;
                    break;
                }
            }
            if (! $ok) {
                continue;
            }
            $rows[] = [
                'tab' => $person->tab_number,
                'name' => $person->full_name,
                'position' => $pos,
                'class' => $class,
                'shop' => $person->shop_code,
                'brigade' => $person->brigade_code,
            ];
        }
        usort($rows, function (array $a, array $b) use ($order) {
            return match ($order) {
                'tab' => strcmp((string) $a['tab'], (string) $b['tab']),
                'position' => strcmp($a['position'].$a['class'].$a['name'], $b['position'].$b['class'].$b['name']),
                'shop' => strcmp((string) $a['shop'].$a['name'], (string) $b['shop'].$b['name']),
                'brigade' => strcmp((string) $a['brigade'].$a['name'], (string) $b['brigade'].$b['name']),
                default => strcmp((string) $a['name'], (string) $b['name']),
            };
        });

        $columns = ['Таб.ном', 'Ф.И.О.', 'Должн.', 'Класс', 'Цех'];
        $keys = ['tab', 'name', 'position', 'class', 'shop'];
        if ($order === 'brigade') {
            $columns[] = 'Бригада';
            $keys[] = 'brigade';
        }

        return [
            'kind' => self::KIND_PERSONNEL,
            'layout' => 'table',
            'title' => 'Список работников на '.$asOf->format('d.m.Y'),
            'columns' => $columns,
            'keys' => $keys,
            'rows' => $rows,
            'message' => $rows === [] ? 'Список пуст.' : null,
        ];
    }

    /**
     * knoch1: смены NR_nsm1 / NR_nsm2.
     *
     * @return array<string, mixed>
     */
    public function nightShifts(string $yearMonth): array
    {
        [$start, $end] = $this->monthBounds($yearMonth);
        $nights = $this->reportNightCodes();
        $people = $this->personnelByUserId();
        $counts = [];
        foreach ($this->assignmentsBetween($start, $end) as $row) {
            $shift = trim((string) $row->shift_code);
            if (! in_array($shift, $nights, true)) {
                continue;
            }
            $counts[(int) $row->user_id] = ($counts[(int) $row->user_id] ?? 0) + 1;
        }
        $rows = [];
        $n = 0;
        $total = 0;
        foreach ($counts as $userId => $kol) {
            if ($kol === 0) {
                continue;
            }
            $n++;
            $person = $people[$userId] ?? null;
            $total += $kol;
            $rows[] = [
                'n' => $n,
                'tab' => $person?->tab_number,
                'name' => $person?->full_name,
                'position' => $person?->position_code,
                'count' => $kol,
            ];
        }
        usort($rows, fn ($a, $b) => strcmp((string) $a['tab'], (string) $b['tab']));
        foreach ($rows as $i => $row) {
            $rows[$i]['n'] = $i + 1;
        }

        return [
            'kind' => self::KIND_NIGHTS,
            'layout' => 'table',
            'title' => 'Количество отработанных ночных смен за '.$this->monthTitle($yearMonth),
            'columns' => ['№', 'Таб.', 'Ф.И.О.', 'Должность', 'Ночных смен'],
            'keys' => ['n', 'tab', 'name', 'position', 'count'],
            'rows' => $rows,
            'footer_text' => $rows === [] ? null : 'ИТОГО: '.$total,
            'message' => $rows === [] ? 'Нет ночных смен за месяц.' : null,
        ];
    }

    /**
     * kdoppr / sppom: RAZBSM.PRIM1.
     *
     * @return array<string, mixed>
     */
    public function extraMarks(string $yearMonth, string $mark, string $mode = 'all'): array
    {
        $mark = trim($mark);
        [$start, $end] = $this->monthBounds($yearMonth);
        $mode = in_array($mode, ['all', 'with', 'without'], true) ? $mode : 'all';
        if ($mark === '') {
            return [
                'kind' => self::KIND_EXTRA_MARKS,
                'layout' => 'table',
                'title' => 'Справка по отработке смен с пометкой',
                'columns' => ['Таб.', 'Ф.И.О.', 'Должность', 'Цех', 'Смен'],
                'keys' => ['tab', 'name', 'position', 'shop', 'count'],
                'rows' => [],
                'message' => 'Укажите пометку разбивки (RAZBSM.PRIM1).',
                'available' => $this->availableExtraMarks(),
            ];
        }
        $title = match ($mode) {
            'with' => 'Список работников, отработавших смены с пометкой "'.$mark.'"',
            'without' => 'Список работников, не отработавших смены с пометкой "'.$mark.'"',
            default => 'Справка по отработке смен с пометкой "'.$mark.'"',
        };
        $assignments = $this->assignmentsBetween($start, $end)->groupBy('user_id');
        $rows = [];
        foreach ($this->personnelOn($end, firedExclusive: true) as $person) {
            if ($person->hired_on && $person->hired_on->gt($end)) {
                continue;
            }
            $kol = 0;
            foreach ($assignments->get($person->user_id, collect()) as $row) {
                if (! $this->filled($row->shift_code)) {
                    continue;
                }
                $bd = $row->breakdown ?: $this->breakdownFor($row);
                if ($bd && trim((string) $bd->extra_mark) === $mark) {
                    $kol++;
                }
            }
            $include = match ($mode) {
                'with' => $kol > 0,
                'without' => $kol === 0,
                default => true,
            };
            if (! $include) {
                continue;
            }
            $rows[] = [
                'tab' => $person->tab_number,
                'name' => $person->full_name,
                'position' => $person->position_code,
                'shop' => $person->shop_code,
                'count' => $kol > 0 ? $kol : '',
            ];
        }

        return [
            'kind' => self::KIND_EXTRA_MARKS,
            'layout' => 'table',
            'title' => $title,
            'subtitle' => 'за период с '.$start->format('d.m.Y').' по '.$end->format('d.m.Y'),
            'columns' => ['Таб.', 'Ф.И.О.', 'Должность', 'Цех', 'Смен'],
            'keys' => ['tab', 'name', 'position', 'shop', 'count'],
            'rows' => $rows,
            'available' => $this->availableExtraMarks(),
            'message' => $rows === [] ? 'Нет строк по выбранной пометке.' : null,
        ];
    }

    /**
     * perer1: LSH.PER1 / totals.per1.
     *
     * @return array<string, mixed>
     */
    public function overtime(string $yearMonth): array
    {
        $accounts = ArmAccount::query()
            ->with('user.personnel')
            ->where('year_month', $yearMonth)
            ->where('kind', 'primary')
            ->orderBy('user_id')
            ->orderBy('ls_number')
            ->get();
        $rows = [];
        $n = 0;
        $total = 0.0;
        foreach ($accounts as $account) {
            $per1 = (float) (($account->totals ?? [])['per1'] ?? 0);
            if ($per1 <= 0) {
                continue;
            }
            $n++;
            $person = $account->user?->personnel;
            $total += $per1;
            $rows[] = [
                'n' => $n,
                'tab' => $person?->tab_number,
                'name' => $person?->full_name ?: $account->user?->name,
                'position' => $account->position_code ?: $person?->position_code,
                'hours' => round($per1, 2),
            ];
        }

        return [
            'kind' => self::KIND_OVERTIME,
            'layout' => 'table',
            'title' => 'Сверхурочные часы за '.$this->monthTitle($yearMonth),
            'columns' => ['№', 'Таб.', 'Ф.И.О.', 'Должность', 'Переработка (час.)'],
            'keys' => ['n', 'tab', 'name', 'position', 'hours'],
            'rows' => $rows,
            'footer_text' => $rows === [] ? null : 'Итого: '.round($total, 2),
            'message' => $rows === [] ? 'Нет сверхурочных часов за месяц (нужны собранные ЛС).' : null,
        ];
    }

    /**
     * pereryv1: выход на работу после ≥12 записей NARJAD без смены.
     *
     * @return array<string, mixed>
     */
    public function workBreaks(CarbonInterface $date): array
    {
        $onDate = NaryadAssignment::query()
            ->with('user.personnel')
            ->whereDate('plan_date', $date->toDateString())
            ->get();
        $rows = [];
        foreach ($onDate as $row) {
            if (! $this->filled($row->shift_code)) {
                continue;
            }
            $prev = NaryadAssignment::query()
                ->where('user_id', $row->user_id)
                ->whereDate('plan_date', '<', $date->toDateString())
                ->orderByDesc('plan_date')
                ->get();
            $ns = 0;
            $codes = [];
            foreach ($prev as $item) {
                if ($this->filled($item->shift_code)) {
                    break;
                }
                $ns++;
                $work = $this->workCode($item);
                if ($work !== '' && ! in_array($work, $codes, true)) {
                    $codes[] = $work;
                }
            }
            if ($ns < 12) {
                continue;
            }
            $person = $row->user?->personnel;
            $rows[] = [
                'tab' => $person?->tab_number,
                'name' => $person?->full_name ?: $row->user?->name,
                'position' => $person?->position_code,
                'kinds' => implode(' ', $codes),
            ];
        }

        return [
            'kind' => self::KIND_BREAKS,
            'layout' => 'table',
            'title' => 'Вышли на работу после перерыва более 12 дней '.$date->format('d.m.Y'),
            'columns' => ['Таб.', 'Ф.И.О.', 'Должность', 'Вид отвлечения'],
            'keys' => ['tab', 'name', 'position', 'kinds'],
            'rows' => $rows,
            'message' => $rows === [] ? 'Не найдено перерывов >12 дней на '.$date->format('d.m.Y').'.' : null,
        ];
    }

    /**
     * spr_przd.
     *
     * @return array<string, mixed>
     */
    public function holidayHours(CarbonInterface $from, CarbonInterface $to): array
    {
        $people = $this->personnelByUserId();
        $grouped = $this->assignmentsBetween($from, $to)->groupBy('user_id');
        $rows = [];
        $n = 0;
        foreach ($grouped as $userId => $days) {
            $p1 = 0.0;
            $pr = 0.0;
            $p2 = 0.0;
            $p2p = 0.0;
            foreach ($days as $row) {
                if ($this->filled($row->shift_code)) {
                    $p1 += (float) $row->hours_holiday;
                    $pr += (float) $row->hours_holiday_reserve;
                }
                $p2 += (float) $row->hours_holiday_2;
                $p2p += (float) $row->hours_holiday_2_reserve;
            }
            if ($p1 <= 0 && $pr <= 0 && $p2 <= 0 && $p2p <= 0) {
                continue;
            }
            $n++;
            $person = $people[$userId] ?? null;
            $rows[] = [
                'n' => $n,
                'tab' => $person?->tab_number,
                'name' => $person?->full_name,
                'position' => $person?->position_code,
                'p1' => $p1 > 0 ? round($p1, 2) : '',
                'reserve' => $pr > 0 ? round($pr, 2) : '',
                'p2' => $p2 > 0 ? round($p2, 2) : '',
                'p2p' => $p2p > 0 ? round($p2p, 2) : '',
            ];
        }

        return [
            'kind' => self::KIND_HOLIDAYS,
            'layout' => 'table',
            'title' => 'Праздничные часы с '.$from->format('d.m.Y').' по '.$to->format('d.m.Y'),
            'columns' => ['№', 'Таб.', 'Ф.И.О.', 'Должность', 'в 1 лицо', 'резерв', 'в 2 л. МШ', 'в 2 л. П/М'],
            'keys' => ['n', 'tab', 'name', 'position', 'p1', 'reserve', 'p2', 'p2p'],
            'rows' => $rows,
            'message' => $rows === [] ? 'Нет праздничных часов за период ни у одного работника.' : null,
        ];
    }

    /**
     * nazn_per.
     *
     * @return array<string, mixed>
     */
    public function appointments(int $userId, CarbonInterface $from, CarbonInterface $to): array
    {
        $person = ArmPersonnel::query()->where('user_id', $userId)->first();
        if (! $person) {
            return [
                'kind' => self::KIND_APPOINTMENTS,
                'layout' => 'table',
                'title' => 'Назначения за период',
                'columns' => ['Дата', 'Маршр. смена', 'Начало', 'Окончание', 'Часы'],
                'keys' => ['date', 'route', 'start', 'end', 'hours'],
                'rows' => [],
                'message' => 'Укажите работника.',
            ];
        }
        $rows = [];
        $assignments = NaryadAssignment::query()
            ->with('breakdown')
            ->where('user_id', $userId)
            ->whereBetween('plan_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('plan_date')
            ->get();
        foreach ($assignments as $row) {
            $bd = $row->breakdown ?: $this->breakdownFor($row);
            $shift = trim((string) $row->shift_code);
            $work = $this->workCode($row);
            $rows[] = [
                'date' => $row->plan_date?->format('d.m.Y'),
                'route' => $work.($shift !== '' ? '.'.$shift : ''),
                'start' => $bd ? FoxTime::format($bd->start_hours).' '.(string) $bd->appearance_start : (string) $row->start_time,
                'end' => $bd ? FoxTime::format($bd->end_hours).' '.(string) $bd->appearance_end : (string) $row->end_time,
                'hours' => (float) $row->hours_total > 0 ? round((float) $row->hours_total, 3) : '',
            ];
        }

        return [
            'kind' => self::KIND_APPOINTMENTS,
            'layout' => 'table',
            'title' => 'Назначения для '.trim((string) $person->full_name).' с '.$from->format('d.m.Y').' по '.$to->format('d.m.Y'),
            'columns' => ['Дата', 'Маршр. смена', 'Начало', 'Окончание', 'Продолжительность'],
            'keys' => ['date', 'route', 'start', 'end', 'hours'],
            'rows' => $rows,
            'message' => $rows === [] ? 'Нет назначений за период.' : null,
        ];
    }

    /**
     * @return list<string>
     */
    public function availableExtraMarks(): array
    {
        return ArmShiftBreakdown::query()
            ->whereNotNull('extra_mark')
            ->where('extra_mark', '!=', '')
            ->distinct()
            ->orderBy('extra_mark')
            ->pluck('extra_mark')
            ->map(fn ($v) => trim((string) $v))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    public function kodvih(): string
    {
        return 'Е';
    }

    public function kodbol(): string
    {
        return 'Б';
    }

    public function kodtrav(): string
    {
        return 'Т';
    }

    /**
     * @return list<string>
     */
    public function reportNightCodes(): array
    {
        return ['3+', '4+'];
    }

    private function shopCombined(): string
    {
        $planir = $this->planir->settings(NaryadNorm::query()->first());

        return trim((string) ($planir['shop_combined'] ?? '11'));
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function month(array $params, string $key = 'month'): string
    {
        $raw = (string) ($params[$key] ?? $params['month'] ?? Carbon::now()->format('Y-m'));
        if (! preg_match('/^\d{4}-\d{2}$/', $raw)) {
            return Carbon::now()->format('Y-m');
        }

        return $raw;
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function day(array $params, string $key): Carbon
    {
        $raw = (string) ($params[$key] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return Carbon::createFromFormat('Y-m-d', $raw)->startOfDay();
        }
        if (preg_match('/^\d{4}-\d{2}$/', $raw)) {
            return Carbon::createFromFormat('Y-m', $raw)->startOfMonth();
        }

        return Carbon::now()->startOfDay();
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function monthBounds(string $yearMonth): array
    {
        $start = Carbon::createFromFormat('Y-m', $yearMonth)->startOfMonth();

        return [$start, $start->copy()->endOfMonth()];
    }

    private function monthTitle(string $yearMonth): string
    {
        $d = Carbon::createFromFormat('Y-m', $yearMonth);
        $name = self::MONTHS[(int) $d->format('n')] ?? $d->format('m');

        return $name.' '.$d->format('Y');
    }

    /**
     * @return Collection<int, NaryadAssignment>
     */
    private function assignmentsBetween(CarbonInterface $from, CarbonInterface $to): Collection
    {
        return NaryadAssignment::query()
            ->with(['user.personnel', 'breakdown'])
            ->whereBetween('plan_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('plan_date')
            ->get();
    }

    /**
     * @return array<int, ArmPersonnel>
     */
    private function personnelByUserId(): array
    {
        $out = [];
        foreach (ArmPersonnel::query()->get() as $person) {
            $out[(int) $person->user_id] = $person;
        }

        return $out;
    }

    /**
     * @return Collection<int, ArmPersonnel>
     */
    private function personnelOn(CarbonInterface $asOf, bool $firedExclusive): Collection
    {
        $day = $asOf->toDateString();

        return ArmPersonnel::query()
            ->where(function ($q) use ($day) {
                $q->whereNull('hired_on')->orWhereDate('hired_on', '<=', $day);
            })
            ->where(function ($q) use ($day, $firedExclusive) {
                $q->whereNull('fired_on');
                if ($firedExclusive) {
                    $q->orWhereDate('fired_on', '>', $day);
                } else {
                    $q->orWhereDate('fired_on', '>=', $day);
                }
            })
            ->orderBy('full_name')
            ->get();
    }

    private function activeOn(ArmPersonnel $person, CarbonInterface $from, CarbonInterface $to): bool
    {
        if ($person->hired_on && $person->hired_on->gt($to)) {
            return false;
        }
        if ($person->fired_on && $person->fired_on->lte($from)) {
            return false;
        }

        return true;
    }

    /**
     * @param  list<int>  $userIds
     * @return array<int, list<ArmAppointment>>
     */
    private function positionsIndex(array $userIds, CarbonInterface $until): array
    {
        if ($userIds === []) {
            return [];
        }
        $out = [];
        $rows = ArmAppointment::query()
            ->whereIn('user_id', $userIds)
            ->whereDate('appointed_on', '<=', $until->toDateString())
            ->orderBy('appointed_on')
            ->get();
        foreach ($rows as $row) {
            $out[(int) $row->user_id][] = $row;
        }

        return $out;
    }

    /**
     * @param  array<int, list<ArmAppointment>>  $index
     */
    private function positionOn(int $userId, CarbonInterface $date, ?ArmPersonnel $person, array $index): string
    {
        $found = null;
        foreach ($index[$userId] ?? [] as $appt) {
            if ($appt->appointed_on && $appt->appointed_on->lte($date)) {
                $found = $appt;
            }
        }

        return trim((string) ($found?->position_code ?: $person?->position_code ?: ''));
    }

    private function mashPomBucket(string $position): ?string
    {
        if ($position === 'МШ') {
            return 'mash';
        }
        if ($position === 'П/М') {
            return 'pom';
        }

        return null;
    }

    /**
     * @return array{code: string, mash: array<int, int>, pom: array<int, int>, mash_total: int, pom_total: int}
     */
    private function emptyDayBlock(string $code, int $days): array
    {
        $mash = [];
        $pom = [];
        for ($d = 1; $d <= $days; $d++) {
            $mash[$d] = 0;
            $pom[$d] = 0;
        }

        return [
            'code' => $code,
            'mash' => $mash,
            'pom' => $pom,
            'mash_total' => 0,
            'pom_total' => 0,
        ];
    }

    /**
     * @return array<string, true>
     */
    private function holidaySet(CarbonInterface $from, CarbonInterface $to): array
    {
        $set = [];
        foreach (ArmHoliday::query()->whereBetween('holiday_date', [$from->toDateString(), $to->toDateString()])->get() as $row) {
            $set[$row->holiday_date->toDateString()] = true;
        }

        return $set;
    }

    private function workCode(NaryadAssignment $row): string
    {
        return mb_strtoupper(trim((string) ($row->work_code ?: $row->route_number)));
    }

    private function filled(?string $shift): bool
    {
        return trim((string) $shift) !== '';
    }

    private function isLineRoute(string $work): bool
    {
        if ($work === '' || ! is_numeric($work)) {
            return false;
        }
        $planir = $this->planir->settings(NaryadNorm::query()->first());
        $n = (int) $work;

        return $n >= (int) ($planir['route_from'] ?? 1) && $n <= (int) ($planir['route_to'] ?? 35);
    }

    private function markOf(NaryadAssignment $row): string
    {
        $mark = trim((string) ($row->mark_code ?? ''));
        if ($mark !== '') {
            return $mark;
        }

        return $row->registered ? '+' : '';
    }

    private function allHours(NaryadAssignment $row): float
    {
        return (float) $row->hours_line
            + (float) $row->hours_reserve
            + (float) $row->hours_line_2
            + (float) $row->hours_reserve_2;
    }

    /**
     * @param  Collection<int, NaryadAssignment>  $days
     */
    private function roundSum(Collection $days, string $field): float
    {
        return round($days->sum($field), 2);
    }

    /**
     * @param  array<string, true>  $holidays
     */
    private function periodNorm(?ArmPersonnel $person, Carbon $from, Carbon $to, float $monthHours, float $dayHours, array $holidays): float
    {
        $fullMonth = $from->copy()->startOfMonth()->equalTo($from) && $from->copy()->endOfMonth()->isSameDay($to);
        $hiredInside = $person?->hired_on && $person->hired_on->betweenIncluded($from, $to);
        $firedInside = $person?->fired_on && $person->fired_on->betweenIncluded($from, $to);
        if ($fullMonth && ! $hiredInside && ! $firedInside && $monthHours > 0) {
            return $monthHours;
        }
        if ($dayHours <= 0) {
            return $monthHours;
        }
        $begin = $person?->hired_on && $person->hired_on->gt($from) ? $person->hired_on->copy() : $from->copy();
        $finish = $person?->fired_on && $person->fired_on->lt($to) ? $person->fired_on->copy() : $to->copy();
        $koldn = 0;
        for ($d = $begin->copy(); $d->lte($finish); $d->addDay()) {
            if ($d->dayOfWeek === Carbon::SUNDAY) {
                continue;
            }
            if (isset($holidays[$d->toDateString()])) {
                continue;
            }
            $koldn++;
        }

        return $koldn * $dayHours;
    }

    private function isCombinedShop(ArmPersonnel $person, string $shopCombined): bool
    {
        return $shopCombined !== '' && trim((string) $person->shop_code) === $shopCombined;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function flag(array $filters, string $key, bool $default): bool
    {
        if (! array_key_exists($key, $filters)) {
            return $default;
        }
        $v = $filters[$key];

        return $v === true || $v === 1 || $v === '1' || $v === 'on';
    }

    private function breakdownFor(NaryadAssignment $row): ?ArmShiftBreakdown
    {
        if ($row->arm_shift_breakdown_id) {
            return $row->breakdown;
        }
        $key = trim((string) ($row->work_code ?: $row->route_number));
        if ($row->shift_code) {
            $key = $key.' ('.$row->shift_code.')';
        }

        return $this->shiftHours->findForRouteOnDate($row->plan_date, $key);
    }
}
