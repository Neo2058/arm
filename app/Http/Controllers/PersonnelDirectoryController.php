<?php

namespace App\Http\Controllers;

use App\Services\Arm\PersonnelDirectory;
use App\Services\ClickHouseService;
use Illuminate\Http\Request;

class PersonnelDirectoryController extends Controller
{
    public function index(Request $request, PersonnelDirectory $directory)
    {
        $actor = $request->user();
        abort_unless($directory->canAccess($actor), 403);
        $filters = $request->validate(['q' => 'nullable|string|max:100', 'active' => 'nullable|boolean']);
        $query = $directory->query($actor)->orderBy('tab_number');
        if ($q = trim($filters['q'] ?? '')) {
            $operator = $query->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
            $query->where(fn ($inner) => $inner->where('tab_number', $operator, '%'.$q.'%')
                ->orWhere('full_name', $operator, '%'.$q.'%')->orWhere('brigade_code', $q));
        }
        if ($request->boolean('active')) {
            $query->where(fn ($inner) => $inner->whereNull('fired_on')->orWhereDate('fired_on', '>=', today()));
        }
        $people = $query->paginate(50)->withQueryString();

        return view('personnel.index', [
            'people' => $people, 'q' => $filters['q'] ?? '', 'active' => $request->boolean('active'),
            'column' => $actor->isInstructor() ? $actor->profile?->column : null,
            'isInstructor' => $actor->isInstructor(),
        ]);
    }

    public function show(Request $request, PersonnelDirectory $directory, int $personnel)
    {
        abort_unless($directory->canAccess($request->user()), 403);
        $person = $directory->query($request->user())->findOrFail($personnel);

        return view('personnel.show', [
            'person' => $person, 'canEdit' => $directory->canEdit($request->user()),
            'canAssignColumn' => $request->user()->isAdmin(),
        ]);
    }

    public function update(Request $request, PersonnelDirectory $directory, int $personnel)
    {
        abort_unless($directory->canAccess($request->user()), 403);
        $data = $request->validate($directory->rules($request->user()));
        $person = $directory->update($request->user(), $personnel, $data);
        ClickHouseService::log('personnel.updated', $person->id, ['fields' => array_keys($data)]);

        return redirect()->route('personnel.show', $person)->with('success', 'Карточка сохранена.');
    }
}
