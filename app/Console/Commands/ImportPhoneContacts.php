<?php

namespace App\Console\Commands;

use App\Models\ArmPersonnel;
use App\Models\PhoneContact;
use App\Services\PhoneDirectory;
use Illuminate\Console\Command;

class ImportPhoneContacts extends Command
{
    protected $signature = 'phones:import-personnel {--apply : Save valid non-conflicting contacts}';

    protected $description = 'Audit/import personnel phones without changing personnel records';

    public function handle(): int
    {
        $seen = PhoneContact::pluck('name', 'phone')->all();
        $counts = ['new' => 0, 'existing' => 0, 'invalid' => 0, 'conflicts' => 0];
        ArmPersonnel::where(fn ($q) => $q->whereNull('fired_on')->orWhereDate('fired_on', '>=', today()))
            ->orderBy('id')->each(function ($person) use (&$seen, &$counts) {
                foreach (['phone_primary', 'phone_secondary'] as $field) {
                    $raw = trim((string) $person->$field);
                    if ($raw === '') {
                        continue;
                    }
                    $phone = PhoneDirectory::normalize($raw);
                    if (! $phone) {
                        $counts['invalid']++;
                        $this->warn("Invalid format: personnel_id={$person->id}, field={$field}");

                        continue;
                    }
                    $name = preg_replace('/\s+/u', ' ', trim($person->full_name));
                    if (isset($seen[$phone])) {
                        $same = mb_strtolower($seen[$phone]) === mb_strtolower($name);
                        $counts[$same ? 'existing' : 'conflicts']++;
                        if (! $same) {
                            $this->warn("Name conflict: personnel_id={$person->id}, field={$field}");
                        }

                        continue;
                    }
                    $seen[$phone] = $name;
                    $counts['new']++;
                    if ($this->option('apply')) {
                        PhoneContact::firstOrCreate(['phone' => $phone], ['name' => $name, 'personnel_id' => $person->id]);
                    }
                }
            });
        $this->line(json_encode($counts));

        return self::SUCCESS;
    }
}
