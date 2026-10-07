<?php

namespace App\Services\Arm;

use App\Models\ArmAccount;
use App\Models\ArmCode;
use App\Models\NaryadNorm;
use Illuminate\Support\Collection;

/**
 * Выгрузка бухгалтерии как PODG_LSH → LSBUH.DBF (dBASE III, CP866).
 *
 * Поля и длины — живой дамп Печатников. После строк формул: TABN_OLD,
 * TABN←LKM.TABNOM1, DEPO если чужое депо, NOMLS+5, DDE='1' при NR_dde='+'.
 */
class LsbuhExportService
{
    /** @var list<array{name: string, type: string, length: int, decimal?: int}> */
    public const FIELDS = [
        ['name' => 'GODMES', 'type' => 'C', 'length' => 6],
        ['name' => 'TABN', 'type' => 'C', 'length' => 4],
        ['name' => 'VOPL', 'type' => 'C', 'length' => 3],
        ['name' => 'TARST', 'type' => 'N', 'length' => 10, 'decimal' => 3],
        ['name' => 'PROCNT', 'type' => 'N', 'length' => 6, 'decimal' => 2],
        ['name' => 'VROTR', 'type' => 'N', 'length' => 6, 'decimal' => 2],
        ['name' => 'ZAKAZ', 'type' => 'C', 'length' => 7],
        ['name' => 'PROF', 'type' => 'C', 'length' => 3],
        ['name' => 'OTBOR', 'type' => 'L', 'length' => 1],
        ['name' => 'NOMLS', 'type' => 'C', 'length' => 1],
        ['name' => 'DDE', 'type' => 'C', 'length' => 1],
        ['name' => 'OSN_PROF', 'type' => 'C', 'length' => 3],
        ['name' => 'TABN_OLD', 'type' => 'C', 'length' => 4],
        ['name' => 'DEPO', 'type' => 'C', 'length' => 4],
        ['name' => 'DEPOKOM', 'type' => 'C', 'length' => 4],
    ];

    /** @var list<string> */
    public const CSV_COLUMNS = [
        'GODMES', 'TABN', 'VOPL', 'TARST', 'PROCNT', 'VROTR', 'ZAKAZ',
        'PROF', 'NOMLS', 'DDE', 'OSN_PROF', 'TABN_OLD', 'DEPO', 'DEPOKOM',
    ];

    /** COMKOD 0101 Печатников. */
    public const OWN_DEPO = 'ТЧ15';

    /** @var array<string, string>|null */
    private ?array $osnProfByPosition = null;

    public function __construct(
        private PlanirRulesService $planir,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function rows(string $yearMonth, ?string $kind = null): array
    {
        $godmes = str_replace('-', '', $yearMonth);
        $settings = $this->planir->settings(NaryadNorm::query()->first());
        $ownDepo = trim((string) ($settings['own_depo'] ?? self::OWN_DEPO)) ?: self::OWN_DEPO;
        $dde = trim((string) ($settings['dde'] ?? '')) === '+' ? '1' : '';

        $query = ArmAccount::query()
            ->with(['user.personnel', 'lines'])
            ->where('year_month', $yearMonth);
        if (in_array($kind, ['primary', 'repeat'], true)) {
            $query->where('kind', $kind);
        }

        /** @var Collection<int, ArmAccount> $accounts */
        $accounts = $query->orderBy('kind')->orderBy('ls_number')->orderBy('id')->get();

        $out = [];
        foreach ($accounts as $account) {
            $personnel = $account->user?->personnel;
            $tab = trim((string) ($personnel?->tab_number ?? sprintf('%04d', $account->user_id)));
            $mainTab = trim((string) ($personnel?->main_tab_number ?? ''));
            $personDepo = trim((string) ($personnel?->depo_code ?? ''));
            $depo = ($personDepo !== '' && $personDepo !== $ownDepo) ? $personDepo : '';
            $tabn = $mainTab !== '' ? $mainTab : $tab;
            $osnProf = $this->osnProf($account->position_code);

            foreach ($account->lines as $line) {
                $nomls = (string) ($line->ls_number ?: $account->ls_number ?: '0');
                $nomls = substr($nomls, 0, 1);
                if ($nomls === '') {
                    $nomls = '0';
                }
                if ($mainTab !== '' || $depo !== '') {
                    $nomls = $this->bumpNomls($nomls);
                }
                $prof = trim((string) ($line->profession ?? ''));
                $out[] = [
                    'godmes' => $godmes,
                    'tabn' => $tabn,
                    'vopl' => (string) ($line->pay_code ?? ''),
                    'tarst' => (float) $line->tariff,
                    'procnt' => (float) $line->percent,
                    'vrotr' => (float) $line->hours,
                    'zakaz' => (string) ($line->cost_code ?? ''),
                    'prof' => $prof,
                    'otbor' => false,
                    'nomls' => $nomls,
                    'dde' => $dde,
                    'osn_prof' => $osnProf !== '' ? $osnProf : $prof,
                    'tabn_old' => $tab,
                    'depo' => $depo,
                    'depokom' => '',
                ];
            }
        }

        return $out;
    }

    public function csv(string $yearMonth, ?string $kind = null): string
    {
        $handle = fopen('php://temp', 'w+');
        if ($handle === false) {
            return '';
        }
        fputcsv($handle, self::CSV_COLUMNS);
        foreach ($this->rows($yearMonth, $kind) as $row) {
            $line = [];
            foreach (self::CSV_COLUMNS as $col) {
                $key = strtolower($col);
                $line[] = $this->csvValue($key, $row[$key] ?? '');
            }
            fputcsv($handle, $line);
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv === false ? '' : $csv;
    }

    public function write(string $path, string $yearMonth, ?string $kind = null): int
    {
        $rows = $this->rows($yearMonth, $kind);
        $dbfRows = [];
        foreach ($rows as $row) {
            $dbfRows[] = [
                'GODMES' => $row['godmes'],
                'TABN' => $row['tabn'],
                'VOPL' => $row['vopl'],
                'TARST' => $row['tarst'],
                'PROCNT' => $row['procnt'],
                'VROTR' => $row['vrotr'],
                'ZAKAZ' => $row['zakaz'],
                'PROF' => $row['prof'],
                'OTBOR' => $row['otbor'],
                'NOMLS' => $row['nomls'],
                'DDE' => $row['dde'],
                'OSN_PROF' => $row['osn_prof'],
                'TABN_OLD' => $row['tabn_old'],
                'DEPO' => $row['depo'],
                'DEPOKOM' => $row['depokom'],
            ];
        }
        DbfWriter::write($path, self::FIELDS, $dbfRows);

        return count($dbfRows);
    }

    /**
     * @return list<array{name: string, type: string, length: int, decimal: int}>
     */
    public function fieldLayout(): array
    {
        $out = [];
        foreach (self::FIELDS as $field) {
            $out[] = [
                'name' => $field['name'],
                'type' => $field['type'],
                'length' => $field['length'],
                'decimal' => (int) ($field['decimal'] ?? 0),
            ];
        }

        return $out;
    }

    private function osnProf(?string $position): string
    {
        $position = trim((string) $position);
        if ($position === '') {
            return '';
        }
        if ($this->osnProfByPosition === null) {
            $this->osnProfByPosition = [];
            foreach (ArmCode::query()->where('book_code', '05')->get(['code', 'nom']) as $code) {
                $key = trim((string) $code->code);
                $nom = trim((string) $code->nom);
                if ($key !== '' && $nom !== '') {
                    $this->osnProfByPosition[$key] = $nom;
                }
            }
        }

        return $this->osnProfByPosition[$position] ?? '';
    }

    private function bumpNomls(string $nomls): string
    {
        $n = (int) $nomls + 5;
        if ($n < 0 || $n > 9) {
            return '*';
        }

        return (string) $n;
    }

    private function csvValue(string $key, mixed $value): string
    {
        return match ($key) {
            'tarst' => number_format((float) $value, 3, '.', ''),
            'procnt', 'vrotr' => number_format((float) $value, 2, '.', ''),
            'otbor' => $value ? 'T' : 'F',
            default => (string) $value,
        };
    }
}
