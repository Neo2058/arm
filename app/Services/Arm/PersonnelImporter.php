<?php

namespace App\Services\Arm;

use App\Enums\UserRole;
use App\Models\ArmAbsence;
use App\Models\ArmAppointment;
use App\Models\ArmPersonnel;
use App\Models\User;
use App\Models\UserProfile;
use Carbon\Carbon;
use Illuminate\Support\Str;

class PersonnelImporter
{
    public function importPersonnel(string $dbfPath): int
    {
        $count = 0;
        foreach ((new DbfReader($dbfPath))->records() as $row) {
            $tab = $this->tab($row['TABNOM'] ?? '');
            if ($tab === '') {
                continue;
            }
            $this->upsertPerson($tab, $row);
            $count++;
        }

        return $count;
    }

    public function importAppointments(string $dbfPath): int
    {
        $users = ArmPersonnel::query()->pluck('user_id', 'tab_number');
        $chunk = [];
        $count = 0;
        $now = now();

        foreach ((new DbfReader($dbfPath))->records() as $row) {
            if ($this->isDeleted($row['UDAL'] ?? '')) {
                continue;
            }
            $tab = $this->tab($row['TABNOM'] ?? '');
            $date = $row['DTNAZN'] ?? null;
            if ($tab === '' || ! $date) {
                continue;
            }
            $chunk[] = [
                'user_id' => $users[$tab] ?? null,
                'tab_number' => $tab,
                'appointed_on' => $date,
                'position_code' => trim((string) ($row['DOLZN'] ?? '')),
                'class_code' => $this->nullable(trim((string) ($row['KLASS'] ?? ''))),
                'created_at' => $now,
                'updated_at' => $now,
            ];
            if (count($chunk) >= 200) {
                $this->upsertAppointments($chunk);
                $count += count($chunk);
                $chunk = [];
            }
        }
        if ($chunk !== []) {
            $this->upsertAppointments($chunk);
            $count += count($chunk);
        }

        return $count;
    }

    public function importAbsences(string $dbfPath): int
    {
        $users = ArmPersonnel::query()->pluck('user_id', 'tab_number');
        $chunk = [];
        $count = 0;
        $now = now();

        foreach ((new DbfReader($dbfPath))->records() as $row) {
            if ($this->isDeleted($row['UDAL'] ?? '')) {
                continue;
            }
            $tab = $this->tab($row['TABNOM'] ?? '');
            $start = $row['DTN'] ?? null;
            if ($tab === '' || ! $start) {
                continue;
            }
            $end = $row['DTK'] ?? $start;
            $chunk[] = [
                'user_id' => $users[$tab] ?? null,
                'tab_number' => $tab,
                'starts_on' => $start,
                'ends_on' => $end ?: $start,
                'kind_code' => trim((string) ($row['VIDOTV'] ?? '')),
                'created_at' => $now,
                'updated_at' => $now,
            ];
            if (count($chunk) >= 200) {
                $this->upsertAbsences($chunk);
                $count += count($chunk);
                $chunk = [];
            }
        }
        if ($chunk !== []) {
            $this->upsertAbsences($chunk);
            $count += count($chunk);
        }

        return $count;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function upsertPerson(string $tab, array $row): void
    {
        $fio = trim((string) ($row['FIO'] ?? ''));
        $displayName = rtrim($fio, '+ ');
        $position = trim((string) ($row['DOLZN'] ?? ''));
        $class = trim((string) ($row['KLASS'] ?? ''));
        $tel1 = trim((string) ($row['TEL1'] ?? ''));
        $pbr = trim((string) ($row['PBR'] ?? ''));
        $hired = $row['DTUSTR'] ?? null;
        $fired = $row['DTUV'] ?? null;

        $user = $this->findOrCreateUser($tab, $displayName !== '' ? $displayName : $tab, $fired);

        UserProfile::updateOrCreate(
            ['user_id' => $user->id],
            [
                'tab_number' => $tab,
                'phoneNumber' => $tel1 !== '' ? $tel1 : null,
                'position' => $position !== '' ? $position : null,
                'normative_class' => $class !== '' ? $class : null,
                'is_brigadir' => $pbr === '+',
                'is_pomoshnik' => str_contains($position, 'П'),
            ]
        );

        ArmPersonnel::updateOrCreate(
            ['tab_number' => $tab],
            [
                'user_id' => $user->id,
                'full_name' => $fio !== '' ? $fio : $displayName,
                'hired_on' => $hired,
                'fired_on' => $fired,
                'position_code' => $this->nullable($position),
                'class_code' => $this->nullable($class),
                'phone_primary' => $this->nullable($tel1),
                'phone_secondary' => $this->nullable(trim((string) ($row['TEL2'] ?? ''))),
                'seniority_on' => $row['DTVISL'] ?? null,
                'brigade_code' => $this->nullable(trim((string) ($row['NBRIG'] ?? ''))),
                'is_brigadier' => $pbr === '+',
                'shop_code' => $this->nullable(trim((string) ($row['CEH'] ?? ''))),
                'med_from' => $row['DTMED1'] ?? null,
                'med_to' => $row['DTMED2'] ?? null,
                'assistant_seniority_on' => $row['DTVISLP'] ?? null,
                'roster_number' => $this->nullable(trim((string) ($row['NOMER'] ?? ''))),
                'assistant_seniority_years' => (int) ($row['VISLP'] ?? 0),
                'early_windows' => $this->windows($row, 'RANVR', 'PRAN'),
                'late_windows' => $this->windows($row, 'POZOK', 'PPOZ'),
                'brigadier_from' => $row['DTBRIG'] ?? null,
                'brigadier_to' => $row['DTOSBRIG'] ?? null,
                'category_code' => $this->nullable(trim((string) ($row['KAT'] ?? ''))),
                'premium_flag' => $this->nullable(trim((string) ($row['OTB_PREM'] ?? ''))),
                'main_tab_number' => $this->nullable(trim((string) ($row['TABNOM1'] ?? ''))),
                'depo_code' => $this->nullable(trim((string) ($row['DEPO'] ?? ''))),
            ]
        );
    }

    private function findOrCreateUser(string $tab, string $name, mixed $fired): User
    {
        $email = 'tab'.$tab.'@arm.local';

        $profile = UserProfile::query()->where('tab_number', $tab)->first();
        $user = $profile?->user ?? User::query()->where('email', $email)->first();

        if (! $user) {
            $user = User::create([
                'name' => $name,
                'email' => $email,
                'password' => Str::random(40),
                'role' => UserRole::DRIVER,
                'is_active' => true,
            ]);
        } else {
            $user->name = $name;
            if ($user->role === UserRole::DRIVER || $user->role === null) {
                $user->role = UserRole::DRIVER;
            }
        }

        if ($user->isDriver() && $fired && Carbon::parse($fired)->lt(now()->startOfDay())) {
            $user->is_active = false;
        }
        $user->save();

        return $user;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<array{time: string, reason: string}>
     */
    private function windows(array $row, string $timePrefix, string $reasonPrefix): array
    {
        $out = [];
        for ($i = 1; $i <= 3; $i++) {
            $time = trim((string) ($row[$timePrefix.$i] ?? ''));
            $reason = trim((string) ($row[$reasonPrefix.$i] ?? ''));
            if ($time === '' && $reason === '') {
                continue;
            }
            $out[] = ['time' => $time, 'reason' => $reason];
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $chunk
     */
    private function upsertAppointments(array $chunk): void
    {
        ArmAppointment::upsert(
            $chunk,
            ['tab_number', 'appointed_on', 'position_code'],
            ['user_id', 'class_code', 'updated_at']
        );
    }

    /**
     * @param  list<array<string, mixed>>  $chunk
     */
    private function upsertAbsences(array $chunk): void
    {
        ArmAbsence::upsert(
            $chunk,
            ['tab_number', 'starts_on', 'ends_on', 'kind_code'],
            ['user_id', 'updated_at']
        );
    }

    private function tab(mixed $value): string
    {
        return trim((string) $value);
    }

    private function isDeleted(mixed $udal): bool
    {
        return trim((string) $udal) !== '';
    }

    private function nullable(string $value): ?string
    {
        return $value === '' ? null : $value;
    }
}
