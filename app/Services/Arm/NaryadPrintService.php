<?php

namespace App\Services\Arm;

use App\Models\ArmAppointment;
use App\Models\ArmCode;
use App\Models\ArmPersonnel;
use App\Models\ArmRoster;
use App\Models\ArmShiftBreakdown;
use App\Models\ArmSignature;
use App\Models\DeviationsCatalog;
use App\Models\NaryadAssignment;
use App\Models\NaryadNorm;
use App\Models\NaryadQuota;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Печать нарядов — логика DOKMENU.PRG / pecnar.
 *
 * tip_nar=1 полный наряд, tip_nar=2 выписка в комнату отдыха.
 * Составы: RASST/RASST1 + ELKODIF kodspr=03 (app_nar). Имена вне линии — kodspr=07.
 * Подписи: текущий нарядчик + PODPISI с ненулевым порядком. TEXTN — доп. строки с формы.
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
     *     extra_text: list<string>,
     *     absences: list<array{title: string, names: list<string>}>,
     *     signatures: list<array{position: string, name: string}>,
     *     warnings: list<string>,
     *     printed_on: string
     * }
     */
    public function build(
        CarbonInterface|string $date,
        string $kind = self::KIND_FULL,
        ?User $user = null,
        string $extraText = '',
    ): array {
        $day = $date instanceof CarbonInterface
            ? $date->copy()->startOfDay()
            : Carbon::parse($date)->startOfDay();
        $kind = $kind === self::KIND_EXTRACT ? self::KIND_EXTRACT : self::KIND_FULL;
        $settings = $this->settings(NaryadNorm::query()->first());
        if ($kind === self::KIND_EXTRACT) {
            $settings['show_wagons'] = false;
            $settings['show_end_on_line'] = true;
            $settings['show_end_off_line'] = true;
        }
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
            'extra_text' => [],
            'absences' => [],
            'signatures' => $this->signatures($user),
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

        $catalogs = $this->printCatalogs($day, $settings);
        $used = [];
        $prevRoute = null;
        $leftover = '';
        $bannerPending = trim((string) $settings['header_banner']) !== '';
        foreach ($slots as $slot) {
            $people = $this->peopleForSlot($assignments, $slot['route'], $slot['shift']);
            foreach ($people as $person) {
                $used[$person['user_id']] = true;
            }
            $isFirst = $prevRoute === null;
            $newRoute = $isFirst || ! $this->sameCode($prevRoute, $slot['route']);
            $built = $this->buildSlotRows(
                $slot,
                $people,
                $newRoute,
                $isFirst,
                $kind,
                $settings,
                $day,
                $catalogs,
                $leftover,
                $bannerPending,
            );
            foreach ($built['rows'] as $row) {
                $sheet['rows'][] = $row;
            }
            foreach ($built['warnings'] as $warning) {
                $sheet['warnings'][] = $warning;
            }
            if (! ($built['skipped'] ?? false)) {
                $prevRoute = $slot['route'];
                $leftover = $built['leftover'];
                $bannerPending = $built['banner_pending'];
            }
        }

        if ($kind === self::KIND_FULL) {
            $sheet['extra_text'] = $this->extraTextLines($extraText);
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
            'show_wagons' => true,
            'two_person_layout' => false,
            'split_rest_by_position' => true,
            'note_indent' => 40,
            'merge_extra_roster' => false,
            'header_banner' => '',
            'extra_text_prompt' => false,
        ];
        $stored = is_array($norm?->planir) && is_array($norm->planir['print'] ?? null)
            ? $norm->planir['print']
            : [];
        if (! array_key_exists('merge_extra_roster', $stored) && (int) ($planir['train_count'] ?? 0) === 2) {
            $defaults['merge_extra_roster'] = true;
        }

        return array_replace($defaults, $stored, [
            'night_shift_codes' => $planir['night_shift_codes'],
            'rest_codes' => $planir['rest_codes'],
            'route_from' => (int) ($planir['route_from'] ?? $defaults['route_from']),
            'route_to' => (int) ($planir['route_to'] ?? $defaults['route_to']),
            'line_mode' => (int) ($planir['line_mode'] ?? 1),
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
     * @param  array{rosters: array<string, array{instrument: string, name: string}>, costs: array<string, string>}  $catalogs
     * @return array{rows: list<array<string, mixed>>, warnings: list<string>, skipped: bool, leftover: string, banner_pending: bool}
     */
    private function buildSlotRows(
        array $slot,
        array $people,
        bool $newRoute,
        bool $isFirst,
        string $kind,
        array $settings,
        CarbonInterface $day,
        array $catalogs,
        string $leftover,
        bool $bannerPending,
    ): array {
        if ($people === [] && $slot['skip_vacant']) {
            return [
                'rows' => [],
                'warnings' => [],
                'skipped' => true,
                'leftover' => $leftover,
                'banner_pending' => $bannerPending,
            ];
        }

        $warnings = [];
        $isLine = $this->isLineRoute($slot['route'], $settings);
        $rows = [];

        if ($newRoute && $leftover !== '') {
            $rows[] = $this->leftoverRow($leftover);
            $leftover = '';
        }
        if ($newRoute && ! $isFirst) {
            $rows[] = $this->spacerRow();
        }
        if ($newRoute && $bannerPending && ! $isLine) {
            $banner = trim((string) $settings['header_banner']);
            if ($banner !== '') {
                $rows[] = $this->bannerRow($banner);
                $rows[] = $this->spacerRow();
            }
            $bannerPending = false;
        }

        $column = $this->routeColumn($slot['route'], $newRoute, $settings, $catalogs, $leftover);
        $leftover = $column['leftover'];

        foreach ($people as $i => $person) {
            $people[$i]['mark'] = $this->personMark((int) $person['user_id'], $day, $settings);
        }

        if ($people === []) {
            $rows[] = $this->makeRow($slot, $newRoute, $kind, $settings, [
                'machinist' => '. . . . . . . . .',
                'assistant' => $settings['two_person_layout'] ? '. . . . . . . . .' : '',
                'vacant' => true,
                'left' => $column['left'],
                'composition' => $column['composition'],
            ]);

            return [
                'rows' => $rows,
                'warnings' => [],
                'skipped' => false,
                'leftover' => $leftover,
                'banner_pending' => $bannerPending,
            ];
        }

        $chunks = $this->pairPeople($people, $isLine, $slot, $warnings);
        foreach ($chunks as $index => $pair) {
            $rows[] = $this->makeRow($slot, $newRoute && $index === 0, $kind, $settings, [
                'machinist' => $this->formatName($pair['machinist'] ?? null),
                'assistant' => $this->formatName($pair['assistant'] ?? null, $settings['two_person_layout']),
                'vacant' => false,
                'left' => $index === 0 ? $column['left'] : '',
                'composition' => $index === 0 ? $column['composition'] : '',
            ]);
        }

        return [
            'rows' => $rows,
            'warnings' => $warnings,
            'skipped' => false,
            'leftover' => $leftover,
            'banner_pending' => $bannerPending,
        ];
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
     * @param  array{machinist: string, assistant: string, vacant: bool, left?: string, composition?: string}  $names
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
        $left = array_key_exists('left', $names)
            ? (string) $names['left']
            : ($showRoute ? trim($slot['route'].' '.$slot['shift']) : '');

        return [
            'route' => $showRoute ? $slot['route'] : '',
            'shift' => $slot['shift'],
            'route_label' => $left,
            'composition' => (string) ($names['composition'] ?? ''),
            'machinist' => $names['machinist'],
            'assistant' => $names['assistant'],
            'start' => $startText,
            'end' => $endText,
            'time' => trim($startText.' '.$endText),
            'note' => $note['inline'],
            'note_lines' => $note['extra'],
            'vacant' => $names['vacant'],
            'is_line' => $isLine,
            'spacer' => false,
            'banner' => '',
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
        $code = (string) ($day->dayOfWeek + 1);
        $fromBook = ArmCode::query()
            ->where('book_code', '47')
            ->where('code', $code)
            ->value('name');
        if (is_string($fromBook) && trim($fromBook) !== '') {
            return mb_strtoupper(trim($fromBook));
        }

        return mb_strtoupper($day->copy()->locale('ru')->isoFormat('dddd'));
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array{rosters: array<string, array{instrument: string, name: string}>, costs: array<string, string>}
     */
    private function printCatalogs(CarbonInterface $day, array $settings): array
    {
        $kinds = [ArmRoster::KIND_PRIMARY];
        if ($settings['merge_extra_roster']) {
            $kinds[] = ArmRoster::KIND_EXTRA;
        }
        $consistNames = ArmCode::query()
            ->where('book_code', '03')
            ->get()
            ->keyBy(fn (ArmCode $code) => trim((string) $code->code));
        $rosters = [];
        foreach ($kinds as $kind) {
            $rows = ArmRoster::query()
                ->whereDate('roster_date', $day->toDateString())
                ->where('kind', $kind)
                ->orderBy('id')
                ->get();
            foreach ($rows as $row) {
                $route = trim((string) $row->route_number);
                if ($route === '' || isset($rosters[$route])) {
                    continue;
                }
                $instrument = trim((string) ($row->instrument ?? ''));
                $name = '';
                if ($instrument !== '' && isset($consistNames[$instrument])) {
                    $name = trim((string) $consistNames[$instrument]->name);
                }
                $rosters[$route] = ['instrument' => $instrument, 'name' => $name];
            }
        }

        $costs = [];
        foreach (ArmCode::query()->where('book_code', '07')->orderBy('id')->get() as $code) {
            $key = trim((string) $code->code);
            if ($key === '' || isset($costs[$key])) {
                continue;
            }
            $costs[$key] = trim((string) $code->name);
        }

        return ['rosters' => $rosters, 'costs' => $costs];
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  array{rosters: array<string, array{instrument: string, name: string}>, costs: array<string, string>}  $catalogs
     * @return array{left: string, leftover: string, composition: string}
     */
    private function routeColumn(
        string $route,
        bool $newRoute,
        array $settings,
        array $catalogs,
        string $leftover,
    ): array {
        if (! $newRoute) {
            if ($leftover !== '') {
                return [
                    'left' => $this->leftoverColumn($leftover),
                    'leftover' => '',
                    'composition' => trim($leftover),
                ];
            }

            return ['left' => '', 'leftover' => '', 'composition' => ''];
        }

        if ($this->usesConsistColumn($route)) {
            return $this->lineRouteColumn($route, $settings, $catalogs);
        }

        return $this->namedRouteColumn($route, $catalogs);
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  array{rosters: array<string, array{instrument: string, name: string}>, costs: array<string, string>}  $catalogs
     * @return array{left: string, leftover: string, composition: string}
     */
    private function lineRouteColumn(string $route, array $settings, array $catalogs): array
    {
        $work = $this->cut($route, 2);
        if (! $settings['show_wagons']) {
            return [
                'left' => $this->pad($work, 13),
                'leftover' => '',
                'composition' => '',
            ];
        }
        $roster = $this->rosterForRoute($route, $catalogs['rosters'])
            ?? ['instrument' => '', 'name' => ''];
        $nazel = (string) ($roster['name'] ?? '');
        $instr = (string) ($roster['instrument'] ?? '');
        if ($nazel === '' && $instr === '') {
            return [
                'left' => $this->pad($work, 13),
                'leftover' => '',
                'composition' => '',
            ];
        }
        $sost = $this->cut($nazel, 11).' '.$instr;
        $ws = $work.' '.$sost.' ';
        $ins = $this->cut(trim($this->right(trim($ws), 3)), 2);
        $ws15 = $this->cut($ws, 15);
        $pz = mb_strpos($ws15, '-');
        if ($nazel === '' || $pz === false) {
            $left = $this->pad($this->cut($ws15, 2).'    '.$ins, 13);

            return ['left' => $left, 'leftover' => '', 'composition' => trim($instr)];
        }
        $head = $this->cut($this->cut($ws15, $pz + 1).' ', 9);
        $left = $this->pad($head.$ins, 13);
        $leftover = trim(mb_substr($ws15, $pz + 1));

        return ['left' => $left, 'leftover' => $leftover, 'composition' => trim($nazel.' '.$instr)];
    }

    /**
     * @param  array{rosters: array<string, array{instrument: string, name: string}>, costs: array<string, string>}  $catalogs
     * @return array{left: string, leftover: string, composition: string}
     */
    private function namedRouteColumn(string $route, array $catalogs): array
    {
        $name = $catalogs['costs'][$route] ?? '';
        if ($name === '') {
            foreach ($catalogs['costs'] as $code => $label) {
                if ($this->sameCode($code, $route)) {
                    $name = $label;
                    break;
                }
            }
        }
        if ($name === '') {
            $name = $route;
        }
        $ws = $this->pad($name, 18);
        $left = $this->cut($ws, 13);
        $leftover = trim(mb_substr($ws, 13));

        return ['left' => $left, 'leftover' => $leftover, 'composition' => ''];
    }

    /**
     * @param  array<string, array{instrument: string, name: string}>  $rosters
     * @return array{instrument: string, name: string}|null
     */
    private function rosterForRoute(string $route, array $rosters): ?array
    {
        if (isset($rosters[$route])) {
            return $rosters[$route];
        }
        foreach ($rosters as $code => $row) {
            if ($this->sameCode((string) $code, $route)) {
                return $row;
            }
        }

        return null;
    }

    private function usesConsistColumn(string $route): bool
    {
        return is_numeric($route);
    }

    private function leftoverColumn(string $leftover): string
    {
        return '   '.$this->pad($leftover, 10);
    }

    /**
     * @return list<array{position: string, name: string}>
     */
    private function signatures(?User $user): array
    {
        $out = [];
        if ($user) {
            $position = trim((string) ($user->profile?->position ?? ''));
            $out[] = [
                'position' => $position !== '' ? $position : 'Нарядчик',
                'name' => trim((string) $user->name),
            ];
        }
        $rows = ArmSignature::query()
            ->where('sort_order', '!=', 0)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
        foreach ($rows as $row) {
            $out[] = [
                'position' => trim((string) $row->position),
                'name' => trim((string) $row->full_name),
            ];
        }
        foreach ($out as $i => $sign) {
            $out[$i]['position'] = $this->pad($sign['position'], 45);
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function extraTextLines(string $text): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $lines = explode("\n", $text);
        while ($lines !== [] && trim((string) end($lines)) === '') {
            array_pop($lines);
        }
        $out = [];
        foreach ($lines as $line) {
            $out[] = rtrim($line);
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function spacerRow(): array
    {
        return [
            'route' => '',
            'shift' => '',
            'route_label' => '',
            'composition' => '',
            'machinist' => '',
            'assistant' => '',
            'start' => '',
            'end' => '',
            'time' => '',
            'note' => '',
            'note_lines' => [],
            'vacant' => false,
            'is_line' => false,
            'spacer' => true,
            'banner' => '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function leftoverRow(string $leftover): array
    {
        $row = $this->spacerRow();
        $row['spacer'] = false;
        $row['route_label'] = $this->leftoverColumn($leftover);
        $row['composition'] = trim($leftover);

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function bannerRow(string $banner): array
    {
        $row = $this->spacerRow();
        $row['banner'] = $banner;

        return $row;
    }

    private function cut(string $text, int $length): string
    {
        if ($length <= 0) {
            return '';
        }

        return mb_substr($text, 0, $length);
    }

    private function pad(string $text, int $length): string
    {
        $text = $this->cut($text, $length);

        return $text.str_repeat(' ', max(0, $length - mb_strlen($text)));
    }

    private function right(string $text, int $length): string
    {
        if ($length <= 0) {
            return '';
        }
        $len = mb_strlen($text);
        if ($len <= $length) {
            return $text;
        }

        return mb_substr($text, $len - $length);
    }
}
