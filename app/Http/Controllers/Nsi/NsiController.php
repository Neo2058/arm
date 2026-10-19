<?php

namespace App\Http\Controllers\Nsi;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Naryad\EnsuresDispatcher;
use App\Models\ArmCode;
use App\Models\ArmCodeBook;
use App\Models\ArmMonthNorm;
use App\Models\ArmPayFormula;
use App\Models\ArmPremiumRate;
use App\Models\ArmRoster;
use App\Models\ArmSeniorityBand;
use App\Models\ArmSignature;
use App\Models\ArmTariff;
use App\Models\NaryadNorm;
use App\Models\User;
use App\Services\Arm\AccountingImporter;
use App\Services\Arm\PlanirRulesService;
use App\Services\ClickHouseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\View\View;

class NsiController extends Controller implements HasMiddleware
{
    use EnsuresDispatcher;

    public function index(): View
    {
        $this->abortIfNotDispatcher();

        return view('nsi.index', [
            'pads' => $this->pads(),
        ]);
    }

    public function codes(): View
    {
        $this->abortIfNotDispatcher();
        $books = ArmCodeBook::query()->orderBy('kodspr')->get();

        return view('nsi.codes.index', [
            'books' => $books,
            'section' => 'codes',
        ]);
    }

    public function codesShow(ArmCodeBook $book): View
    {
        $this->abortIfNotDispatcher();
        $codes = $book->codes()->orderBy('code')->orderBy('nom')->paginate(80);

        return view('nsi.codes.show', [
            'book' => $book,
            'codes' => $codes,
            'section' => 'codes',
        ]);
    }

    public function codesStore(Request $request, ArmCodeBook $book): RedirectResponse
    {
        $this->abortIfNotDispatcher();
        $data = $this->validateCode($request, $book);
        $record = ArmCode::create([
            'book_code' => $book->kodspr,
            'code' => $data['code'],
            'name' => $data['name'],
            'nom' => $data['nom'] ?? '',
        ]);
        $record->syncSpecialized();
        ClickHouseService::log('nsi.code.created', $record->id, [
            'book' => $book->kodspr,
            'code' => $record->code,
        ]);

        return back()->with('ok', 'Элемент справочника добавлен');
    }

    public function codesUpdate(Request $request, ArmCodeBook $book, ArmCode $code): RedirectResponse
    {
        $this->abortIfNotDispatcher();
        abort_unless($code->book_code === $book->kodspr, 404);
        $data = $this->validateCode($request, $book, $code);
        $code->update([
            'code' => $data['code'],
            'name' => $data['name'],
            'nom' => $data['nom'] ?? '',
        ]);
        $code->syncSpecialized();
        ClickHouseService::log('nsi.code.updated', $code->id, [
            'book' => $book->kodspr,
            'code' => $code->code,
        ]);

        return back()->with('ok', 'Элемент справочника сохранён');
    }

    public function codesDestroy(ArmCodeBook $book, ArmCode $code): RedirectResponse
    {
        $this->abortIfNotDispatcher();
        abort_unless($code->book_code === $book->kodspr, 404);
        $code->dropSpecialized();
        $id = $code->id;
        $value = $code->code;
        $code->delete();
        ClickHouseService::log('nsi.code.deleted', $id, [
            'book' => $book->kodspr,
            'code' => $value,
        ]);

        return back()->with('ok', 'Элемент справочника удалён');
    }

    public function catalogs(): View
    {
        $this->abortIfNotDispatcher();

        return view('nsi.catalogs', [
            'items' => $this->catalogLinks(),
            'section' => 'catalogs',
        ]);
    }

    public function signatures(): View
    {
        $this->abortIfNotDispatcher();

        return view('nsi.signatures', [
            'signatures' => ArmSignature::query()->orderBy('sort_order')->orderBy('id')->get(),
            'section' => 'catalogs',
        ]);
    }

    public function signaturesStore(Request $request): RedirectResponse
    {
        $this->abortIfNotDispatcher();
        $data = $request->validate([
            'sort_order' => 'nullable|integer',
            'position' => 'required|string|max:45',
            'full_name' => 'nullable|string|max:18',
        ]);
        $signature = ArmSignature::create([
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'position' => $data['position'],
            'full_name' => $data['full_name'] ?? '',
        ]);
        ClickHouseService::log('nsi.signature.created', $signature->id, ['position' => $signature->position]);

        return back()->with('ok', 'Подпись добавлена');
    }

    public function signaturesUpdate(Request $request, ArmSignature $signature): RedirectResponse
    {
        $this->abortIfNotDispatcher();
        $data = $request->validate([
            'sort_order' => 'nullable|integer',
            'position' => 'required|string|max:45',
            'full_name' => 'nullable|string|max:18',
        ]);
        $signature->update([
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'position' => $data['position'],
            'full_name' => $data['full_name'] ?? '',
        ]);
        ClickHouseService::log('nsi.signature.updated', $signature->id, ['position' => $signature->position]);

        return back()->with('ok', 'Подпись сохранена');
    }

    public function signaturesDestroy(ArmSignature $signature): RedirectResponse
    {
        $this->abortIfNotDispatcher();
        $id = $signature->id;
        $position = $signature->position;
        $signature->delete();
        ClickHouseService::log('nsi.signature.deleted', $id, ['position' => $position]);

        return back()->with('ok', 'Подпись удалена');
    }

    public function norms(): View
    {
        $this->abortIfNotDispatcher();

        return view('nsi.norms.index', [
            'items' => $this->normLinks(),
            'section' => 'norms',
        ]);
    }

    public function hours(): View
    {
        $this->abortIfNotDispatcher();

        return view('nsi.norms.hours', [
            'rows' => ArmMonthNorm::query()->orderByDesc('year_month')->get(),
            'section' => 'norms',
        ]);
    }

    public function hoursStore(Request $request): RedirectResponse
    {
        $this->abortIfNotDispatcher();
        $data = $request->validate([
            'year_month' => 'required|date_format:Y-m',
            'month_hours' => 'required|numeric|min:0',
            'day_hours' => 'required|numeric|min:0',
        ]);
        $norm = ArmMonthNorm::updateOrCreate(
            ['year_month' => $data['year_month']],
            [
                'month_hours' => $data['month_hours'],
                'day_hours' => $data['day_hours'],
            ]
        );
        ClickHouseService::log('nsi.hours.saved', $norm->id, ['year_month' => $norm->year_month]);

        return back()->with('ok', 'Норма часов сохранена');
    }

    public function hoursUpdate(Request $request, ArmMonthNorm $norm): RedirectResponse
    {
        $this->abortIfNotDispatcher();
        $data = $request->validate([
            'month_hours' => 'required|numeric|min:0',
            'day_hours' => 'required|numeric|min:0',
        ]);
        $norm->update($data);
        ClickHouseService::log('nsi.hours.updated', $norm->id, ['year_month' => $norm->year_month]);

        return back()->with('ok', 'Норма часов обновлена');
    }

    public function hoursDestroy(ArmMonthNorm $norm): RedirectResponse
    {
        $this->abortIfNotDispatcher();
        $id = $norm->id;
        $month = $norm->year_month;
        $norm->delete();
        ClickHouseService::log('nsi.hours.deleted', $id, ['year_month' => $month]);

        return back()->with('ok', 'Норма часов удалена');
    }

    public function premiums(): View
    {
        $this->abortIfNotDispatcher();

        return view('nsi.norms.premiums', [
            'rows' => ArmPremiumRate::query()->orderBy('position_code')->get(),
            'section' => 'norms',
        ]);
    }

    public function premiumsStore(Request $request): RedirectResponse
    {
        $this->abortIfNotDispatcher();
        $data = $request->validate([
            'position_code' => 'required|string|max:8',
            'is_brigadier' => 'nullable|boolean',
            'percent' => 'required|numeric|min:0',
            'name' => 'nullable|string|max:40',
        ]);
        $premium = ArmPremiumRate::updateOrCreate(
            [
                'position_code' => $data['position_code'],
                'is_brigadier' => $request->boolean('is_brigadier'),
            ],
            [
                'percent' => $data['percent'],
                'name' => $data['name'] ?? null,
            ]
        );
        ClickHouseService::log('nsi.premium.saved', $premium->id, ['position_code' => $premium->position_code]);

        return back()->with('ok', 'Процент премии сохранён');
    }

    public function premiumsUpdate(Request $request, ArmPremiumRate $premium): RedirectResponse
    {
        $this->abortIfNotDispatcher();
        $data = $request->validate([
            'percent' => 'required|numeric|min:0',
            'name' => 'nullable|string|max:40',
        ]);
        $premium->update($data);
        ClickHouseService::log('nsi.premium.updated', $premium->id, ['position_code' => $premium->position_code]);

        return back()->with('ok', 'Процент премии обновлён');
    }

    public function premiumsDestroy(ArmPremiumRate $premium): RedirectResponse
    {
        $this->abortIfNotDispatcher();
        $id = $premium->id;
        $code = $premium->position_code;
        $premium->delete();
        ClickHouseService::log('nsi.premium.deleted', $id, ['position_code' => $code]);

        return back()->with('ok', 'Процент премии удалён');
    }

    public function seniority(): View
    {
        $this->abortIfNotDispatcher();

        return view('nsi.norms.seniority', [
            'rows' => ArmSeniorityBand::query()->orderBy('years_from')->get(),
            'section' => 'norms',
        ]);
    }

    public function seniorityStore(Request $request): RedirectResponse
    {
        $this->abortIfNotDispatcher();
        $data = $request->validate([
            'years_from' => 'required|integer|min:0|max:80',
            'years_to' => 'required|integer|min:0|max:80',
            'percent' => 'required|numeric|min:0',
        ]);
        $band = ArmSeniorityBand::updateOrCreate(
            [
                'years_from' => $data['years_from'],
                'years_to' => $data['years_to'],
            ],
            ['percent' => $data['percent']]
        );
        ClickHouseService::log('nsi.seniority.saved', $band->id, ['years_from' => $band->years_from]);

        return back()->with('ok', 'Выслуга сохранена');
    }

    public function seniorityUpdate(Request $request, ArmSeniorityBand $band): RedirectResponse
    {
        $this->abortIfNotDispatcher();
        $data = $request->validate([
            'years_from' => 'required|integer|min:0|max:80',
            'years_to' => 'required|integer|min:0|max:80',
            'percent' => 'required|numeric|min:0',
        ]);
        $band->update($data);
        ClickHouseService::log('nsi.seniority.updated', $band->id, ['years_from' => $band->years_from]);

        return back()->with('ok', 'Выслуга обновлена');
    }

    public function seniorityDestroy(ArmSeniorityBand $band): RedirectResponse
    {
        $this->abortIfNotDispatcher();
        $id = $band->id;
        $band->delete();
        ClickHouseService::log('nsi.seniority.deleted', $id, []);

        return back()->with('ok', 'Выслуга удалена');
    }

    public function tariffs(): View
    {
        $this->abortIfNotDispatcher();

        return view('nsi.norms.tariffs', [
            'rows' => ArmTariff::query()->orderBy('code')->get(),
            'section' => 'norms',
        ]);
    }

    public function tariffsStore(Request $request): RedirectResponse
    {
        $this->abortIfNotDispatcher();
        $data = $request->validate([
            'code' => 'required|string|max:16',
            'name' => 'required|string|max:80',
        ]);
        $book = ArmCodeBook::firstOrCreate(
            ['kodspr' => '41'],
            ['nazspr' => 'Тарифные ставки', 'dbfspr' => 'ELKODIF', 'dlkodel' => 8, 'dlnazel' => 25]
        );
        $record = ArmCode::updateOrCreate(
            ['book_code' => $book->kodspr, 'code' => $data['code'], 'nom' => ''],
            ['name' => $data['name']]
        );
        $record->syncSpecialized();
        ClickHouseService::log('nsi.tariff.saved', $record->id, ['code' => $record->code]);

        return back()->with('ok', 'Тариф сохранён');
    }

    public function tariffsUpdate(Request $request, ArmTariff $tariff): RedirectResponse
    {
        $this->abortIfNotDispatcher();
        $data = $request->validate([
            'name' => 'required|string|max:80',
        ]);
        $tariff->update(['name' => $data['name']]);
        ArmCode::query()
            ->where('book_code', '41')
            ->where('code', $tariff->code)
            ->update(['name' => $data['name']]);
        ClickHouseService::log('nsi.tariff.updated', $tariff->id, ['code' => $tariff->code]);

        return back()->with('ok', 'Тариф обновлён');
    }

    public function tariffsDestroy(ArmTariff $tariff): RedirectResponse
    {
        $this->abortIfNotDispatcher();
        ArmCode::query()->where('book_code', '41')->where('code', $tariff->code)->delete();
        $id = $tariff->id;
        $code = $tariff->code;
        $tariff->delete();
        ClickHouseService::log('nsi.tariff.deleted', $id, ['code' => $code]);

        return back()->with('ok', 'Тариф удалён');
    }

    public function formulas(Request $request): View
    {
        $this->abortIfNotDispatcher();
        $kind = $request->query('kind', 'machinist');
        if (! in_array($kind, ['machinist', 'assistant'], true)) {
            $kind = 'machinist';
        }

        return view('nsi.norms.formulas', [
            'kind' => $kind,
            'rows' => ArmPayFormula::query()->where('kind', $kind)->orderBy('nom')->get(),
            'section' => 'norms',
        ]);
    }

    public function formulasUpdate(Request $request, ArmPayFormula $formula): RedirectResponse
    {
        $this->abortIfNotDispatcher();
        $formula->update(['selected' => $request->boolean('selected')]);
        ClickHouseService::log('nsi.formula.updated', $formula->id, [
            'kind' => $formula->kind,
            'selected' => $request->boolean('selected'),
        ]);

        return back()->with('ok', 'Отбор формы ЛС сохранён');
    }

    public function rosters(Request $request): View
    {
        $this->abortIfNotDispatcher();
        $date = (string) $request->query('date', now()->toDateString());
        $kind = (string) $request->query('kind', ArmRoster::KIND_PRIMARY);
        if (! in_array($kind, [ArmRoster::KIND_PRIMARY, ArmRoster::KIND_EXTRA], true)) {
            $kind = ArmRoster::KIND_PRIMARY;
        }
        $rows = ArmRoster::query()
            ->whereDate('roster_date', $date)
            ->where('kind', $kind)
            ->orderBy('route_number')
            ->get();

        return view('nsi.rosters', [
            'date' => $date,
            'kind' => $kind,
            'rows' => $rows,
            'section' => 'norms',
        ]);
    }

    public function rostersSeed(Request $request): RedirectResponse
    {
        $this->abortIfNotDispatcher();
        $data = $request->validate([
            'date' => 'required|date',
            'kind' => 'required|in:primary,extra',
        ]);
        $routes = ArmCode::query()
            ->where('book_code', '43')
            ->orderBy('code')
            ->get()
            ->filter(function (ArmCode $code) use ($data) {
                $nom = trim((string) $code->nom);
                if ($data['kind'] === ArmRoster::KIND_EXTRA) {
                    return $nom === '2';
                }

                return $nom !== '2';
            });
        foreach ($routes as $route) {
            ArmRoster::firstOrCreate(
                [
                    'roster_date' => $data['date'],
                    'kind' => $data['kind'],
                    'route_number' => $route->code,
                ],
                ['instrument' => null]
            );
        }

        ClickHouseService::log('nsi.roster.seeded', 0, [
            'date' => $data['date'],
            'kind' => $data['kind'],
        ]);

        return redirect()
            ->route('nsi.rosters', ['date' => $data['date'], 'kind' => $data['kind']])
            ->with('ok', 'Маршруты из кодификатора 43 занесены в расстановку');
    }

    public function rostersUpdate(Request $request, ArmRoster $roster): RedirectResponse
    {
        $this->abortIfNotDispatcher();
        $data = $request->validate([
            'instrument' => 'nullable|string|max:4',
        ]);
        $roster->update(['instrument' => $data['instrument'] ?? null]);
        ClickHouseService::log('nsi.roster.updated', $roster->id, ['route_number' => $roster->route_number]);

        return back()->with('ok', 'Инструмент сохранён');
    }

    public function rostersDestroy(ArmRoster $roster): RedirectResponse
    {
        $this->abortIfNotDispatcher();
        $id = $roster->id;
        $route = $roster->route_number;
        $roster->delete();
        ClickHouseService::log('nsi.roster.deleted', $id, ['route_number' => $route]);

        return back()->with('ok', 'Строка расстановки удалена');
    }

    public function arm(): View
    {
        $this->abortIfNotDispatcher();

        return view('nsi.arm.index', [
            'items' => $this->armLinks(),
            'section' => 'arm',
        ]);
    }

    public function users(): View
    {
        $this->abortIfNotDispatcher();
        $users = User::query()
            ->whereIn('role', [
                UserRole::DISPATCHER,
                UserRole::OPERATOR,
                UserRole::ADMIN,
                UserRole::SUPER_ADMIN,
            ])
            ->orderBy('name')
            ->get();

        return view('nsi.arm.users', [
            'users' => $users,
            'section' => 'arm',
        ]);
    }

    public function params(): View
    {
        $this->abortIfNotDispatcher();
        $norm = NaryadNorm::firstOrCreate([], [
            'year_hours' => 2000,
            'month_hours' => 166,
            'week_hours' => 48,
            'min_rest_hours' => 8,
        ]);
        $planir = app(PlanirRulesService::class)->settings($norm);

        return view('nsi.arm.params', [
            'norm' => $norm,
            'planir' => $planir,
            'section' => 'arm',
        ]);
    }

    public function paramsUpdate(Request $request): RedirectResponse
    {
        $this->abortIfNotDispatcher();
        $data = $request->validate([
            'org' => 'nullable|string|max:80',
            'chief' => 'nullable|string|max:80',
            'deputy_ops' => 'nullable|string|max:80',
            'union_chair' => 'nullable|string|max:80',
            'evening_from' => 'nullable|numeric',
            'evening_to' => 'nullable|numeric',
            'night_from' => 'nullable|numeric',
            'night_to' => 'nullable|numeric',
            'route_from' => 'nullable|integer|min:0',
            'route_to' => 'nullable|integer|min:0',
            'advance' => 'nullable|string|max:16',
            'brigadier_percent' => 'nullable|numeric|min:0',
            'tech_hours' => 'nullable|numeric|min:0',
            'ktu' => 'nullable|string|max:4',
            'compat_pay_code' => 'nullable|string|max:8',
            'tea_sum' => 'nullable|numeric|min:0',
            'light_metro' => 'nullable|string|max:4',
            'merge_night' => 'nullable|string|max:4',
            'train_count' => 'nullable|integer|min:0',
            'interval_mode' => 'nullable|in:formula,fixed',
            'interval_hours' => 'nullable|numeric|min:0',
            'rest_day_hours' => 'nullable|numeric|min:0',
            'two_rest_days_hours' => 'nullable|numeric|min:0',
            'min_days_between_rests' => 'nullable|integer|min:0',
            'max_days_without_rest' => 'nullable|integer|min:0',
            'short_interval_hours' => 'nullable|numeric|min:0',
            'max_short_intervals' => 'nullable|integer|min:0',
            'norm_extra_hours' => 'nullable|numeric|min:0',
            'line_mode' => 'nullable|in:1,2',
            'subtract_holiday_hours' => 'nullable|boolean',
            'absence_as_rest' => 'nullable|string|max:4',
            'install_place' => 'nullable|string|max:8',
        ]);
        $norm = NaryadNorm::firstOrCreate([]);
        $incoming = array_filter($data, fn ($value) => $value !== null && $value !== '');
        if ($request->has('subtract_holiday_hours')) {
            $incoming['subtract_holiday_hours'] = $request->boolean('subtract_holiday_hours');
        }
        if (isset($incoming['line_mode'])) {
            $incoming['line_mode'] = (int) $incoming['line_mode'];
        }
        $merged = array_replace(app(PlanirRulesService::class)->settings($norm), $incoming);
        $merged['night_shift_codes'] = app(AccountingImporter::class)->keepNightFivePlus($merged['night_shift_codes'] ?? []);
        $norm->planir = $merged;
        $norm->save();
        ClickHouseService::log('nsi.params.updated', $norm->id, [
            'advance' => $merged['advance'] ?? null,
            'interval_mode' => $merged['interval_mode'] ?? null,
        ]);

        return back()->with('ok', 'Общие параметры сохранены');
    }

    public function advance(): View
    {
        $this->abortIfNotDispatcher();
        $norm = NaryadNorm::firstOrCreate([]);
        $planir = app(PlanirRulesService::class)->settings($norm);

        return view('nsi.arm.advance', [
            'planir' => $planir,
            'section' => 'arm',
        ]);
    }

    public function advanceUpdate(Request $request): RedirectResponse
    {
        $this->abortIfNotDispatcher();
        $data = $request->validate([
            'advance' => 'nullable|string|max:16',
        ]);
        $norm = NaryadNorm::firstOrCreate([]);
        $merged = array_replace(app(PlanirRulesService::class)->settings($norm), [
            'advance' => $data['advance'] ?? '',
        ]);
        $merged['night_shift_codes'] = app(AccountingImporter::class)->keepNightFivePlus($merged['night_shift_codes'] ?? []);
        $norm->planir = $merged;
        $norm->save();
        ClickHouseService::log('nsi.advance.updated', $norm->id, [
            'advance' => $merged['advance'] ?? '',
        ]);

        return back()->with('ok', 'Величина аванса сохранена');
    }

    public function books(): View
    {
        $this->abortIfNotDispatcher();

        return view('nsi.arm.books', [
            'books' => ArmCodeBook::query()->orderBy('kodspr')->get(),
            'section' => 'arm',
        ]);
    }

    public function booksStore(Request $request): RedirectResponse
    {
        $this->abortIfNotDispatcher();
        $data = $request->validate([
            'kodspr' => 'required|string|max:2',
            'nazspr' => 'required|string|max:30',
            'nsisr' => 'nullable|string|max:1',
            'dlkodel' => 'nullable|integer|min:1|max:8',
            'dlnazel' => 'nullable|integer|min:1|max:60',
        ]);
        $book = ArmCodeBook::updateOrCreate(
            ['kodspr' => $data['kodspr']],
            [
                'nazspr' => $data['nazspr'],
                'nsisr' => $data['nsisr'] ?? null,
                'dbfspr' => 'ELKODIF',
                'dlkodel' => (int) ($data['dlkodel'] ?? 2),
                'dlnazel' => (int) ($data['dlnazel'] ?? 30),
            ]
        );
        ClickHouseService::log('nsi.book.saved', $book->id, ['kodspr' => $book->kodspr]);

        return back()->with('ok', 'Справочник сохранён');
    }

    public function booksUpdate(Request $request, ArmCodeBook $book): RedirectResponse
    {
        $this->abortIfNotDispatcher();
        $data = $request->validate([
            'nazspr' => 'required|string|max:30',
            'nsisr' => 'nullable|string|max:1',
            'dlkodel' => 'nullable|integer|min:1|max:8',
            'dlnazel' => 'nullable|integer|min:1|max:60',
        ]);
        $book->update([
            'nazspr' => $data['nazspr'],
            'nsisr' => $data['nsisr'] ?? null,
            'dlkodel' => (int) ($data['dlkodel'] ?? $book->dlkodel),
            'dlnazel' => (int) ($data['dlnazel'] ?? $book->dlnazel),
        ]);
        ClickHouseService::log('nsi.book.updated', $book->id, ['kodspr' => $book->kodspr]);

        return back()->with('ok', 'Название справочника сохранено');
    }

    /**
     * @return list<array{title: string, items: list<array{title: string, href: string, note?: string}>}>
     */
    public function pads(): array
    {
        return [
            [
                'title' => 'Кодификаторы',
                'items' => [
                    ['title' => 'Все справочники KODIF / ELKODIF', 'href' => route('nsi.codes'), 'note' => '23 книги'],
                ],
            ],
            [
                'title' => 'Справочники',
                'items' => $this->catalogLinks(),
            ],
            [
                'title' => 'Нормативы',
                'items' => $this->normLinks(),
            ],
            [
                'title' => 'Настройка АРМ',
                'items' => $this->armLinks(),
            ],
        ];
    }

    /**
     * @return list<array{title: string, href: string, note?: string}>
     */
    private function catalogLinks(): array
    {
        return [
            ['title' => 'Составы (вагоны, kodspr 03)', 'href' => route('nsi.codes.show', '03'), 'note' => 'ELKODIF'],
            ['title' => 'Экипажи т6 / т5', 'href' => route('naryad.index'), 'note' => 'экран нарядчика'],
            ['title' => 'Типы графиков', 'href' => route('naryad.index'), 'note' => 'GRAF / schedule_types'],
            ['title' => 'Разбивка смен', 'href' => route('naryad.index'), 'note' => 'RAZBSM'],
            ['title' => 'Праздничные дни', 'href' => route('naryad.index'), 'note' => 'PRAZD'],
            ['title' => 'Должности', 'href' => route('nsi.codes.show', '05')],
            ['title' => 'Классы квалификации', 'href' => route('nsi.codes.show', '06')],
            ['title' => 'Профессии', 'href' => route('nsi.codes.show', '51')],
            ['title' => 'Подписи ответственных', 'href' => route('nsi.signatures')],
        ];
    }

    /**
     * @return list<array{title: string, href: string, note?: string}>
     */
    private function normLinks(): array
    {
        return [
            ['title' => 'Нормы времени', 'href' => route('nsi.hours'), 'note' => 'NRCHAS'],
            ['title' => 'Календарь', 'href' => route('naryad.index'), 'note' => 'экран нарядчика'],
            ['title' => 'Расстановка составов', 'href' => route('nsi.rosters'), 'note' => 'RASST / RASST1'],
            ['title' => 'Форма лицевого счета', 'href' => route('nsi.formulas'), 'note' => 'FLSM / FLSP отбор'],
            ['title' => 'Процент премии', 'href' => route('nsi.premiums'), 'note' => 'PREM'],
            ['title' => 'Выслуга лет', 'href' => route('nsi.seniority'), 'note' => 'VISL'],
            ['title' => 'Тарифные ставки', 'href' => route('nsi.tariffs'), 'note' => 'ELKODIF 41'],
        ];
    }

    /**
     * @return list<array{title: string, href: string, note?: string}>
     */
    private function armLinks(): array
    {
        return [
            ['title' => 'Пользователи АРМ', 'href' => route('nsi.users')],
            ['title' => 'Общие параметры', 'href' => route('nsi.params'), 'note' => 'NRDPAR / planir'],
            ['title' => 'Настройка аванса', 'href' => route('nsi.advance')],
            ['title' => 'Названия справочников', 'href' => route('nsi.books'), 'note' => 'KODIF.nazspr'],
        ];
    }

    /**
     * @return array{code: string, name: string, nom?: string}
     */
    private function validateCode(Request $request, ArmCodeBook $book, ?ArmCode $existing = null): array
    {
        $lenCode = max(1, (int) $book->dlkodel);
        $lenName = max(1, (int) $book->dlnazel);
        $data = $request->validate([
            'code' => 'required|string|max:'.$lenCode,
            'name' => 'required|string|max:'.$lenName,
            'nom' => 'nullable|string|max:5',
        ]);
        $nom = $data['nom'] ?? '';
        $dup = ArmCode::query()
            ->where('book_code', $book->kodspr)
            ->where('code', $data['code'])
            ->where('nom', $nom)
            ->when($existing, fn ($q) => $q->where('id', '!=', $existing->id))
            ->exists();
        abort_if($dup, 422, 'Такой код уже есть в справочнике');

        return $data;
    }
}
