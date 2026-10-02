<?php

namespace App\Services\Arm;

use App\Models\ArmAppointment;
use App\Models\ArmPersonnel;
use App\Models\ArmShiftBreakdown;
use App\Models\DeviationsCatalog;
use App\Models\NaryadAssignment;
use App\Models\NaryadNorm;
use App\Models\NaryadQuota;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Печать нарядов — логика DOKMENU.PRG / pecnar.
 *
 * tip_nar=1 полный наряд, tip_nar=2 выписка в комнату отдыха.
 * Состав вагонов (RASST) в клон ещё не перенесён: колонка состава пустая.
 */
class NaryadPrintService
{
    public const KIND_FULL = 'full';

    public const KIND_EXTRACT = 'extract';

    public function __construct(
        private ShiftHoursService $shiftHours,
        private PlanirRulesService $planir,
    ) {}

    /**
     * @return array{
     *     kind: string,
     *     date: string,
     *     date_iso: string,
     *     weekday: string,
     *     title: string,
     *     header_route: string,
     *     graph_name: ?string,
     *     message: ?string,
     *     rows: list<array<string, mixed>>,
     *     absences: list<array{title: string, names: list<string>}>,
     *     warnings: list<string>,
     *     printed_on: string
     * }
     */
    public function build(CarbonInterface|string $date, string $kind = self::KIND_FULL): array
    {
        $day = $date instanceof CarbonInterface
            ? $date->copy()->startOfDay()
            : Carbon::parse($date)->startOfDay();
        $kind = $kind === self::KIND_EXTRACT ? self::KIND_EXTRACT : self::KIND_FULL;
        $settings = $this->settings(NaryadNorm::query()->first());
        $weekday = $this->weekdayName($day);
        $title = $kind === self::KIND_EXTRACT
            ? 'ВЫПИСКА ИЗ НАРЯДА ЛОКОМОТИВНЫХ БРИГАД НА '.$day->format('d.m.Y').', '.$weekday
            : 'НАРЯД ЛОКОМОТИВНЫХ БРИГАД НА '.$day->format('d.m.Y').', '.$weekday;

        $sheet = [
            'kind' => $kind,
            'date' => $day->format('d.m.Y'),
            'date_iso' => $day->toDateString(),
            'weekday' => $weekday,
            'title' => $title,
            'header_route' => $kind === self::KIND_FULL ? 'Мар. Состав' : 'Маршрут',
            'graph_name' => null,
            'message' => null,
            'rows' => [],
            'absences' => [],
            'warnings' => [],
            'printed_on' => now()->format('d.m.Y'),
        ];

        $quota = NaryadQuota::with('scheduleType')->whereDate('plan_date', $day->toDateString())->first();
        $graphCode = $quota?->scheduleType?->foxpro_code;
        if ($settings['show_graph_name'] && $quota?->scheduleType) {
            $sheet['graph_name'] = $quota->scheduleType->name;
        }

        if (! $quota || $graphCode === null || $graphCode === '') {
            $sheet['message'] = 'Нет типа графика на эту дату в календаре. Печать тела наряда невозможна.';

            return $sheet;
        }

        $slots = $this->shiftSlots($graphCode, $settings, $kind);
        if ($slots->isEmpty()) {
            $sheet['message'] = 'На график '.$graphCode.' нет разбивки смен.';

            return $sheet;
        }

        $assignments = NaryadAssignment::query()
            ->with('user.personnel')
            ->whereDate('plan_date', $day->toDateString())
            ->orderBy('id')
            ->get();

        $used = [];
        $prevRoute = null;
        foreach ($slots as $slot) {
            $people = $this->peopleForSlot($assignments, $slot['route'], $slot['shift']);
            foreach ($people as $person) {
                $used[$person['user_id']] = true;
            }
            $built = $this->buildSlotRows($slot, $people, $prevRoute, $kind, $settings, $day);
            foreach ($built['rows'] as $row) {
                $sheet['rows'][] = $row;
            }
            foreach ($built['warnings'] as $warning) {
                $sheet['warnings'][] = $warning;
            }
            if (! ($built['skipped'] ?? false)) {
                $prevRoute = $slot['route'];
            }
        }

        if ($kind === self::KIND_FULL) {
            $sheet['absences'] = $this->absenceBlocks($assignments, $used, $settings);
        }

        return $sheet;
    }

    /**
     * @return array<string, mixed>
     */
    public function settings(?NaryadNorm $norm): array
    {
        $planir = $this->planir->settings($norm);
        $defaults = [
            'route_from' => 1,
            'route_to' => 35,
            'extract_shifts' => ['1', '3+', '4+'],
            'vacation_codes' => ['ОД', 'ОТ'],
            'show_graph_name' => false,
            'show_end_on_line' => true,
            'show_end_off_line' => true,
            'two_person_layout' => false,
            'split_rest_by_position' => true,
            'note_indent' => 40,
        ];
        $stored = is_array($norm?->planir) && is_array($norm->planir['print'] ?? null)
            ? $norm->planir['print']
            : [];

        return array_replace($defaults, $stored, [
            'night_shift_codes' => $planir['night_shift_codes'],
            'rest_codes' => $planir['rest_codes'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return Collection<int, array{route: string, shift: string, start: mixed, end: mixed, point_start: string, point_end: string, notes: string, skip_vacant: bool}>
     */
    private function shiftSlots(string $graphCode, array $settings, string $kind): Collection
    {
        $rows = ArmShiftBreakdown::query()
            ->where('graph_code', $graphCode)
            ->orderBy('route_code')
            ->orderBy('sequence')
            ->orderBy('shift_code')
            ->get();

        $slots = collect();
        $seen = [];
        foreach ($rows as $row) {
            $route = trim((string) $row->route_code);
            $shift = trim((string) $row->shift_code);
            if ($route === '' && $shift === '') {
                continue;
            }
            if ($kind === self::KIND_EXTRACT && ! $this->isExtractShift($shift, $settings)) {
                continue;
            }
            $key = $route."\0".$shift;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $notes = (string) $row->notes;
            $slots->push([
                'route' => $route,
                'shift' => $shift,
                'start' => $row->start_hours,
                'end' => $row->end_hours,
                'point_start' => mb_substr(trim((string) $row->appearance_start), 0, 6),
                'point_end' => trim((string) $row->appearance_end),
                'notes' => $notes,
                'skip_vacant' => str_starts_with(ltrim($notes), '*'),
            ]);
        }

        return $slots;
    }

    /**
     * @param  Collection<int, NaryadAssignment>  $assignments
     * @return list<array{user_id: int, name: string, position: string, mark: string}>
     */
    private function peopleForSlot(Collection $assignments, string $route, string $shift): array
    {
        $people = [];
        foreach ($assignments as $assignment) {
            if (! $this->assignmentMatchesSlot($assignment, $route, $shift)) {
                continue;
            }
            $personnel = $assignment->user?->personnel;
            $people[] = [
                'user_id' => (int) $assignment->user_id,
                'name' => $this->displayName($assignment, $personnel),
                'position' => $this->positionOf($personnel),
                'mark' => '',
            ];
        }

        return $people;
    }

    /**
     * @param  array<string, mixed>  $slot
     * @param  list<array<string, mixed>>  $people
     * @param  array<string, mixed>  $settings
     * @return array{rows: list<array<string, mixed>>, warnings: list<string>, skipped: bool}
     */
    private function buildSlotRows(array $slot, array $people, ?string $prevRoute, string $kind, array $settings, CarbonInterface $day): array
    {
        if ($people === [] && $slot['skip_vacant']) {
            return ['rows' => [], 'warnings' => [], 'skipped' => true];
        }

        $warnings = [];
        $isLine = $this->isLineRoute($slot['route'], $settings);
        $showRoute = $prevRoute === null || ! $this->sameCode($prevRoute, $slot['route']);

        foreach ($people as $i => $person) {
            $people[$i]['mark'] = $this->personMark((int) $person['user_id'], $day, $settings);
        }

        if ($people === []) {
            return [
                'rows' => [$this->makeRow($slot, $showRoute, $kind, $settings, [
                    'machinist' => '. . . . . . . . .',
                    'assistant' => $settings['two_person_layout'] ? '. . . . . . . . .' : '',
                    'vacant' => true,
                ])],
                'warnings' => [],
                'skipped' => false,
            ];
        }

        $rows = [];
        $chunks = $this->pairPeople($people, $isLine, $slot, $warnings);
        foreach ($chunks as $index => $pair) {
            $rows[] = $this->makeRow($slot, $showRoute && $index === 0, $kind, $settings, [
                'machinist' => $this->formatName($pair['machinist'] ?? null),
                'assistant' => $this->formatName($pair['assistant'] ?? null, $settings['two_person_layout']),
                'vacant' => false,
            ]);
        }

        return ['rows' => $rows, 'warnings' => $warnings, 'skipped' => false];
    }

    /**
     * @param  list<array<string, mixed>>  $people
     * @param  array<string, mixed>  $slot
     * @param  list<string>  $warnings
     * @return list<array{machinist: ?array, assistant: ?array}>
     */
    private function pairPeople(array $people, bool $isLine, array $slot, array &$warnings): array
    {
        if ($isLine && count($people) > 2) {
            $warnings[] = 'На маршрут '.$slot['route'].' смена '.$slot['shift']
                .' назначено более 2 человек. В наряд не занесены: '
                .implode(', ', array_map(fn ($p) => $p['name'], array_slice($people, 2)));
            $people = array_slice($people, 0, 2);
        }

        $machinists = array_values(array_filter($people, fn ($p) => $p['position'] === 'МШ'));
        $assistants = array_values(array_filter($people, fn ($p) => $p['position'] !== 'МШ'));

        if ($machinists === [] && $assistants !== []) {
            $warnings[] = 'На смену '.$slot['route'].'.'.$slot['shift']
                .' назначены только П/М. Первого ставим в колонку машиниста.';
            $machinists[] = array_shift($assistants);
        }

        $pairs = [];
        $max = max(count($machinists), count($assistants), 1);
        for ($i = 0; $i < $max; $i++) {
            $pairs[] = [
                'machinist' => $machinists[$i] ?? null,
                'assistant' => $assistants[$i] ?? null,
            ];
        }

        return $pairs;
    }

    /**
     * @param  array<string, mixed>  $slot
     * @param  array<string, mixed>  $settings
     * @param  array{machinist: string, assistant: string, vacant: bool}  $names
     * @return array<string, mixed>
     */
    private function makeRow(array $slot, bool $showRoute, string $kind, array $settings, array $names): array
    {
        $isLine = $this->isLineRoute($slot['route'], $settings);
        $showEnd = $isLine ? $settings['show_end_on_line'] : $settings['show_end_off_line'];
        $isNight = $this->sameCode($slot['shift'], '3+')
            || $this->sameCode($slot['shift'], '4+')
            || in_array($slot['shift'], $settings['night_shift_codes'], true);
        $isFirst = $this->sameCode($slot['shift'], '1');

        $startText = FoxTime::format($slot['start']).$slot['point_start'];
        $endText = '-'.FoxTime::format($slot['end']).($slot['point_end'] !== '' ? ' '.$slot['point_end'] : '');

        if ($kind === self::KIND_EXTRACT) {
            if ($isNight) {
                $startText = '';
            }
            if ($isFirst) {
                $endText = '';
            }
        } elseif (! $showEnd) {
            $endText = '';
        }

        $note = $this->splitNotes((string) $slot['notes'], (int) $settings['note_indent']);

        return [
            'route' => $showRoute ? $slot['route'] : '',
            'shift' => $slot['shift'],
            'route_label' => $showRoute ? trim($slot['route'].' '.$slot['shift']) : '',
            'composition' => '',
            'machinist' => $names['machinist'],
            'assistant' => $names['assistant'],
            'start' => $startText,
            'end' => $endText,
            'time' => trim($startText.' '.$endText),
            'note' => $note['inline'],
            'note_lines' => $note['extra'],
            'vacant' => $names['vacant'],
            'is_line' => $isLine,
        ];
    }

    /**
     * @param  Collection<int, NaryadAssignment>  $assignments
     * @param  array<int, bool>  $used
     * @param  array<string, mixed>  $settings
     * @return list<array{title: string, names: list<string>}>
     */
    private function absenceBlocks(Collection $assignments, array $used, array $settings): array
    {
        $catalog = DeviationsCatalog::query()->orderBy('id')->get();
        $kinds = [];
        foreach ($catalog as $item) {
            $keys = array_filter([trim((string) $item->name), trim((string) $item->short_code)]);
            if ($keys === []) {
                continue;
            }
            $kinds[] = [
                'title' => (string) $item->name,
                'keys' => $keys,
                'is_rest' => $this->planir->isRestKey((string) $item->name, [$item->name], $settings['rest_codes'])
                    || $this->planir->isRestKey((string) $item->short_code, [$item->name], $settings['rest_codes']),
            ];
        }
        foreach ($settings['rest_codes'] as $code) {
            $code = trim((string) $code);
            if ($code === '') {
                continue;
            }
            $exists = false;
            foreach ($kinds as $kind) {
                if (in_array($code, $kind['keys'], true) || strcasecmp($kind['title'], $code) === 0) {
                    $exists = true;
                    break;
                }
            }
            if (! $exists) {
                $kinds[] = ['title' => $code, 'keys' => [$code], 'is_rest' => true];
            }
        }

        $blocks = [];
        foreach ($kinds as $kind) {
            $matched = [];
            foreach ($assignments as $assignment) {
                if (isset($used[$assignment->user_id])) {
                    continue;
                }
                if (! $this->assignmentMatchesKeys($assignment, $kind['keys'])) {
                    continue;
                }
                $personnel = $assignment->user?->personnel;
                $matched[] = [
                    'name' => $this->displayName($assignment, $personnel),
                    'position' => $this->positionOf($personnel),
                    'brigade' => (string) ($personnel?->brigade_code ?? ''),
                ];
            }
            if ($matched === []) {
                continue;
            }
            usort($matched, fn ($a, $b) => [$a['brigade'], $a['name']] <=> [$b['brigade'], $b['name']]);
            if ($kind['is_rest'] && $settings['split_rest_by_position']) {
                foreach (['МШ', 'П/М'] as $pos) {
                    $names = [];
                    foreach ($matched as $person) {
                        $isMash = $person['position'] === 'МШ';
                        if (($pos === 'МШ' && $isMash) || ($pos === 'П/М' && ! $isMash)) {
                            $names[] = $person['name'];
                        }
                    }
                    if ($names !== []) {
                        $blocks[] = ['title' => $kind['title'].'      '.$pos, 'names' => $names];
                    }
                }
            } else {
                $blocks[] = [
                    'title' => $kind['title'],
                    'names' => array_values(array_map(fn ($p) => $p['name'], $matched)),
                ];
            }
        }

        return $blocks;
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function personMark(int $userId, CarbonInterface $day, array $settings): string
    {
        $mark = '';
        if ($this->comingFromVacation($userId, $day, $settings['vacation_codes'])) {
            $mark .= 'отп.';
        }
        if ($this->newInPosition($userId, $day)) {
            $mark .= ($mark === '' ? '' : ' ').'!';
        }

        return $mark;
    }

    /**
     * @param  list<string>  $vacationCodes
     */
    private function comingFromVacation(int $userId, CarbonInterface $day, array $vacationCodes): bool
    {
        $previous = NaryadAssignment::query()
            ->where('user_id', $userId)
            ->whereDate('plan_date', '<', $day->toDateString())
            ->orderByDesc('plan_date')
            ->orderByDesc('id')
            ->limit(40)
            ->get();

        foreach ($previous as $row) {
            [, $shift] = $this->shiftHours->parseRouteKey((string) $row->route_number);
            if ($shift === '' && filled($row->shift_code)) {
                $shift = (string) $row->shift_code;
            }
            if ($shift !== '') {
                return false;
            }
            $work = mb_strtoupper(trim((string) ($row->work_code ?: $row->route_number)));
            foreach ($vacationCodes as $code) {
                $code = mb_strtoupper(trim((string) $code));
                if ($code !== '' && ($work === $code || str_starts_with($work, $code))) {
                    return true;
                }
            }
        }

        return false;
    }

    private function newInPosition(int $userId, CarbonInterface $day): bool
    {
        $appointment = ArmAppointment::query()
            ->where('user_id', $userId)
            ->whereDate('appointed_on', '<=', $day->toDateString())
            ->orderByDesc('appointed_on')
            ->first();
        if (! $appointment?->appointed_on) {
            return false;
        }

        return $appointment->appointed_on->gt($day->copy()->subYear());
    }

    private function assignmentMatchesSlot(NaryadAssignment $assignment, string $route, string $shift): bool
    {
        if (filled($assignment->work_code) && filled($assignment->shift_code)) {
            return $this->sameCode((string) $assignment->work_code, $route)
                && $this->sameCode((string) $assignment->shift_code, $shift);
        }
        [$parsedRoute, $parsedShift] = $this->shiftHours->parseRouteKey((string) $assignment->route_number);
        if (! $this->sameCode($parsedRoute, $route)) {
            return false;
        }
        if ($parsedShift === '') {
            return $shift === '';
        }

        return $this->sameCode($parsedShift, $shift);
    }

    /**
     * @param  list<string>  $keys
     */
    private function assignmentMatchesKeys(NaryadAssignment $assignment, array $keys): bool
    {
        $candidates = array_filter([
            trim((string) $assignment->route_number),
            trim((string) $assignment->work_code),
            $this->shiftHours->parseRouteKey((string) $assignment->route_number)[0],
        ]);
        foreach ($candidates as $candidate) {
            foreach ($keys as $key) {
                if ($key !== '' && strcasecmp(trim($candidate), $key) === 0) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function isLineRoute(string $route, array $settings): bool
    {
        if (! is_numeric($route)) {
            return false;
        }
        $n = (int) $route;

        return $n >= (int) $settings['route_from'] && $n <= (int) $settings['route_to'];
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function isExtractShift(string $shift, array $settings): bool
    {
        foreach ($settings['extract_shifts'] as $code) {
            if ($this->sameCode($shift, (string) $code)) {
                return true;
            }
        }

        return false;
    }

    private function sameCode(string $a, string $b): bool
    {
        $a = trim($a);
        $b = trim($b);
        if ($a === '' || $b === '') {
            return $a === $b;
        }
        if (strcasecmp($a, $b) === 0) {
            return true;
        }
        $la = ltrim($a, '0');
        $lb = ltrim($b, '0');

        return $la !== '' && $la === $lb;
    }

    private function positionOf(?ArmPersonnel $personnel): string
    {
        $code = trim((string) $personnel?->position_code);
        if ($code !== '' && (str_contains($code, 'П') || strcasecmp($code, 'П/М') === 0)) {
            return 'П/М';
        }

        return 'МШ';
    }

    private function displayName(NaryadAssignment $assignment, ?ArmPersonnel $personnel): string
    {
        $name = trim((string) ($personnel?->full_name ?: $assignment->user?->name));

        return $name !== '' ? $name : 'б/н';
    }

    /**
     * @param  array<string, mixed>|null  $person
     */
    private function formatName(?array $person, bool $dotsIfEmpty = false): string
    {
        if ($person === null) {
            return $dotsIfEmpty ? '. . . . . . . . .' : '';
        }
        $name = trim((string) $person['name']);
        $mark = trim((string) ($person['mark'] ?? ''));
        if ($mark !== '' && ! str_starts_with($name, '. .')) {
            $name .= ' '.$mark;
        }

        return $name;
    }

    /**
     * @return array{inline: string, extra: list<string>}
     */
    private function splitNotes(string $raw, int $indent): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return ['inline' => '', 'extra' => []];
        }
        if (str_starts_with($raw, '*')) {
            $raw = ltrim(substr($raw, 1));
        }
        $extraBlock = '';
        if (($pos = mb_strpos($raw, '\\')) !== false) {
            $extraBlock = trim(mb_substr($raw, $pos + 1));
            $raw = trim(mb_substr($raw, 0, $pos));
        }
        $extra = [];
        if ($extraBlock !== '') {
            $width = max(20, 80 - $indent);
            $pad = str_repeat(' ', max(0, $indent));
            foreach ($this->wrapText($extraBlock, $width) as $line) {
                $extra[] = $pad.$line;
            }
        }

        return ['inline' => $raw, 'extra' => $extra];
    }

    /**
     * @return list<string>
     */
    private function wrapText(string $text, int $width): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }
        $lines = [];
        while (mb_strlen($text) > $width) {
            $chunk = mb_substr($text, 0, $width);
            $space = mb_strrpos($chunk, ' ');
            $dash = mb_strrpos($chunk, '-');
            $cut = max((int) $space, (int) $dash);
            if ($cut < 1) {
                $cut = $width;
            }
            $lines[] = trim(mb_substr($text, 0, $cut));
            $text = trim(mb_substr($text, $cut));
        }
        if ($text !== '') {
            $lines[] = $text;
        }

        return $lines;
    }

    private function weekdayName(CarbonInterface $day): string
    {
        return $day->copy()->locale('ru')->isoFormat('dddd');
    }
}
