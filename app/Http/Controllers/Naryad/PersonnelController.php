<?php

namespace App\Http\Controllers\Naryad;

use App\Http\Controllers\Controller;
use App\Models\ArmAbsence;
use App\Models\ArmAppointment;
use App\Models\ArmPersonnel;
use App\Services\ClickHouseService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;

class PersonnelController extends Controller implements HasMiddleware
{
    use EnsuresDispatcher;

    public function partialPersonnel(Request $request)
    {
        $this->abortIfNotDispatcher();

        $query = ArmPersonnel::query()->with('user')->orderBy('tab_number');
        if ($request->filled('q')) {
            $q = (string) $request->string('q')->trim();
            $query->where(function ($inner) use ($q) {
                $inner->where('tab_number', 'like', '%'.$q.'%')
                    ->orWhere('full_name', 'like', '%'.$q.'%')
                    ->orWhere('brigade_code', $q);
            });
        }
        if ($request->boolean('active')) {
            $query->where(function ($inner) {
                $inner->whereNull('fired_on')->orWhereDate('fired_on', '>=', now()->toDateString());
            });
        }

        $people = $query->limit(200)->get();

        return view('naryad.partials.personnel', [
            'people' => $people,
            'total' => ArmPersonnel::count(),
            'q' => (string) $request->string('q'),
            'active' => $request->boolean('active'),
        ]);
    }

    public function partialAppointments(Request $request)
    {
        $this->abortIfNotDispatcher();

        $query = ArmAppointment::query()->with('user')->orderByDesc('appointed_on');
        if ($request->filled('tab')) {
            $query->where('tab_number', (string) $request->string('tab')->trim());
        }

        return view('naryad.partials.appointments', [
            'appointments' => $query->limit(200)->get(),
            'total' => ArmAppointment::count(),
            'tab' => (string) $request->string('tab'),
        ]);
    }

    public function storeAppointment(Request $request)
    {
        $this->abortIfNotDispatcher();

        $data = $request->validate([
            'tab_number' => 'required|string|max:8',
            'appointed_on' => 'required|date',
            'position_code' => 'required|string|max:8',
            'class_code' => 'nullable|string|max:4',
        ]);
        $data['tab_number'] = trim($data['tab_number']);
        $person = ArmPersonnel::query()->where('tab_number', $data['tab_number'])->first();
        $data['user_id'] = $person?->user_id;

        $appointment = ArmAppointment::updateOrCreate(
            [
                'tab_number' => $data['tab_number'],
                'appointed_on' => $data['appointed_on'],
                'position_code' => $data['position_code'],
            ],
            $data
        );

        ClickHouseService::log('naryad.appointment.saved', $appointment->id, $data);

        return response()->json(['success' => true]);
    }

    public function destroyAppointment(ArmAppointment $appointment)
    {
        $this->abortIfNotDispatcher();
        $id = $appointment->id;
        $appointment->delete();
        ClickHouseService::log('naryad.appointment.deleted', $id, []);

        return response()->json(['success' => true]);
    }

    public function partialAbsences(Request $request)
    {
        $this->abortIfNotDispatcher();

        $query = ArmAbsence::query()->with('user')->orderByDesc('starts_on');
        if ($request->filled('tab')) {
            $query->where('tab_number', (string) $request->string('tab')->trim());
        }

        return view('naryad.partials.absences', [
            'absences' => $query->limit(200)->get(),
            'total' => ArmAbsence::count(),
            'tab' => (string) $request->string('tab'),
        ]);
    }

    public function storeAbsence(Request $request)
    {
        $this->abortIfNotDispatcher();

        $data = $request->validate([
            'tab_number' => 'required|string|max:8',
            'starts_on' => 'required|date',
            'ends_on' => 'required|date|after_or_equal:starts_on',
            'kind_code' => 'required|string|max:8',
        ]);
        $data['tab_number'] = trim($data['tab_number']);
        $person = ArmPersonnel::query()->where('tab_number', $data['tab_number'])->first();
        $data['user_id'] = $person?->user_id;

        $absence = ArmAbsence::updateOrCreate(
            [
                'tab_number' => $data['tab_number'],
                'starts_on' => $data['starts_on'],
                'ends_on' => $data['ends_on'],
                'kind_code' => $data['kind_code'],
            ],
            $data
        );

        ClickHouseService::log('naryad.absence.saved', $absence->id, $data);

        return response()->json(['success' => true]);
    }

    public function destroyAbsence(ArmAbsence $absence)
    {
        $this->abortIfNotDispatcher();
        $id = $absence->id;
        $absence->delete();
        ClickHouseService::log('naryad.absence.deleted', $id, []);

        return response()->json(['success' => true]);
    }
}
