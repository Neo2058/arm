<?php

namespace App\Services\Journal;

use App\Models\JournalCrewNormative;
use App\Models\JournalNormativeSetting;
use App\Models\User;
use App\Models\UserProfile;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class InstructorTrainingAccounting
{
    public static function nextDate(string $date, int $months): string
    {
        return Carbon::parse($date)->addMonthsNoOverflow($months)->toDateString();
    }

    private function operation(User $actor, array $data, string $action, callable $work): array
    {
        $data['operation_id'] = strtolower($data['operation_id']);
        $fingerprint = hash('sha256', json_encode([$action, $data], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $data, $fingerprint, $work) {
            $actor = User::whereKey($actor->id)->lock(DB::getDriverName() === 'pgsql' ? 'for no key update' : true)->firstOrFail();
            UserProfile::where('user_id', $actor->id)->lockForUpdate()->first();
            $column = app(InstructorTrainingRequests::class)->column($actor);
            $ops = DB::table('instructor_training_operations');
            $receipt = (clone $ops)->where('instructor_id', $actor->id)->where('operation_id', $data['operation_id'])->first();
            if ($receipt) {
                abort_unless($receipt->column === $column, 404);
                abort_unless(hash_equals($receipt->fingerprint, $fingerprint), 409, 'Ключ операции уже использован для другого запроса.');

                return json_decode($receipt->result, true, 512, JSON_THROW_ON_ERROR);
            }
            $result = $work($actor, $column, $data);
            $ops->insert(['instructor_id' => $actor->id, 'operation_id' => $data['operation_id'], 'column' => $column, 'fingerprint' => $fingerprint, 'result' => json_encode($result, JSON_THROW_ON_ERROR), 'created_at' => now()]);

            return $result;
        }, 3);
    }

    public function periods(User $actor, array $input): array
    {
        $data = Validator::make($input, ['operation_id' => 'required|uuid', 'atz_months' => 'required|integer|min:1|max:24', 'atz_line_months' => 'required|integer|min:1|max:24'])->validate();
        foreach (['atz_months', 'atz_line_months'] as $key) {
            $data[$key] = (int) $data[$key];
        }

        return $this->operation($actor, $data, 'atz_periods', function ($actor, $column, $data) {
            JournalNormativeSetting::updateOrCreate(['user_id' => $actor->id], ['column' => $column, 'atz_months' => $data['atz_months'], 'atz_line_months' => $data['atz_line_months']]);
            $norms = JournalCrewNormative::where('instructor_id', $actor->id)->where('column', $column)->whereIn('type', ['atz', 'atz_line'])->lockForUpdate()->get();
            foreach ($norms as $norm) {
                $norm->next_date = $norm->last_date ? self::nextDate($norm->last_date->toDateString(), $data[$norm->type.'_months']) : null;
                $norm->save();
            }

            return ['atz_months' => $data['atz_months'], 'atz_line_months' => $data['atz_line_months']];
        });
    }

    public function complete(User $actor, int $id, array $input): array
    {
        $data = Validator::make($input, ['operation_id' => 'required|uuid', 'version' => 'required|integer|min:1', 'normative_type' => 'required|in:atz,atz_line', 'performed_on' => 'required|date_format:Y-m-d|before_or_equal:today', 'attendees' => 'required|array|min:1', 'attendees.*' => 'required|integer|min:1|distinct'])->validate();
        $data['request_id'] = $id;
        $data['version'] = (int) $data['version'];
        $data['attendees'] = array_map('intval', $data['attendees']);
        sort($data['attendees']);

        return $this->operation($actor, $data, 'atz_complete', function ($actor, $column, $data) use ($id) {
            $request = DB::table('instructor_training_requests')->where('id', $id)->where('instructor_id', $actor->id)->where('column', $column)->lockForUpdate()->first();
            abort_unless($request, 404);
            if ((int) $request->version !== $data['version']) {
                throw ValidationException::withMessages(['version' => 'Карточка изменена. Обновите страницу.']);
            }
            if (DB::table('instructor_training_completions')->where('request_id', $id)->exists()) {
                throw ValidationException::withMessages(['completion' => 'Занятие по этой карточке уже учтено.']);
            }
            $planned = DB::table('instructor_training_members')->where('request_id', $id)->pluck('user_id')->map('intval')->all();
            if (array_diff($data['attendees'], $planned)) {
                throw ValidationException::withMessages(['attendees' => 'Выберите присутствовавших только из состава карточки.']);
            }
            User::whereIn('id', $data['attendees'])->orderBy('id')->lock(DB::getDriverName() === 'pgsql' ? 'for share' : true)->get();
            UserProfile::whereIn('user_id', $data['attendees'])->orderBy('user_id')->lockForUpdate()->get();
            $members = app(InstructorTrainingRequests::class)->crew($column)->whereIn('id', $data['attendees'])->get();
            if ($members->count() !== count($data['attendees'])) {
                throw ValidationException::withMessages(['attendees' => 'Участники должны быть действующими машинистами вашей колонны.']);
            }
            $setting = JournalNormativeSetting::where('user_id', $actor->id)->lockForUpdate()->first();
            $months = (int) ($setting?->{$data['normative_type'].'_months'} ?? 12);
            $completion = DB::table('instructor_training_completions')->insertGetId(['request_id' => $id, 'instructor_id' => $actor->id, 'column' => $column, 'normative_type' => $data['normative_type'], 'performed_on' => $data['performed_on'], 'shift_type' => $request->shift_type, 'period_months' => $months, 'created_at' => now()]);
            foreach ($members as $member) {
                DB::table('instructor_training_attendance')->insert(['completion_id' => $completion, 'user_id' => $member->id, 'name' => $member->name, 'tab_number' => $member->profile?->tab_number]);
                $norm = JournalCrewNormative::where('user_id', $member->id)->where('type', $data['normative_type'])->lockForUpdate()->first();
                if ($norm && ((int) $norm->instructor_id !== $actor->id || $norm->column !== $column)) {
                    throw ValidationException::withMessages(['attendees' => 'Норматив одного из участников закреплён за другим инструктором. Уточните закрепление.']);
                }
                $norm ??= new JournalCrewNormative(['user_id' => $member->id, 'type' => $data['normative_type'], 'instructor_id' => $actor->id, 'column' => $column]);
                if (! $norm->last_date || $norm->last_date->toDateString() < $data['performed_on']) {
                    $norm->last_date = $data['performed_on'];
                    $norm->class = $member->profile?->normative_class;
                }
                $norm->next_date = self::nextDate($norm->last_date->toDateString(), $months);
                $norm->save();
            }
            DB::table('instructor_training_requests')->where('id',$id)->increment('version');

            return ['completion_id' => (string) $completion, 'attendees' => count($data['attendees'])];
        });
    }
}
