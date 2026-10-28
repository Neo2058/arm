<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WorkShift;
use App\Services\Payroll\PersonalWorkShiftService;
use App\Services\Payroll\PersonalWorkTimeMonth;
use App\Services\Payroll\PersonalWorkTimeSettingsEditor;
use App\Services\Payroll\WorkTimeMutationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PersonalWorkTimeController extends Controller
{
    public function __construct(private PersonalWorkShiftService $shifts, private WorkTimeMutationService $mutations, private PersonalWorkTimeSettingsEditor $settings) {}

    public function month(Request $request, PersonalWorkTimeMonth $service): JsonResponse
    {
        $data = $request->validate(['month' => 'required|date_format:Y-m']);

        return $this->json($service->summarize($request->user(), $data['month']));
    }

    public function show(Request $request, int $shift): JsonResponse
    {
        return $this->json(['shift' => $this->view(WorkShift::where('user_id', $request->user()->id)->where('calculation_version', 2)->findOrFail($shift))]);
    }

    public function preview(Request $request): JsonResponse
    {
        return $this->json(['shift' => $this->view($this->shifts->preview($request->user(), $this->shifts->validated($request->all())))]);
    }

    public function store(Request $request): JsonResponse
    {
        $control = $request->validate(['operation_id' => 'required|uuid']);
        $input = $this->shifts->validated($request->all());

        return $this->json($this->mutations->run($request->user(), $control['operation_id'], 'shift.create', $input,
            fn ($user) => ['shift' => $this->view($this->shifts->create($user, $input, $control['operation_id']))]), 201);
    }

    public function previewUpdate(Request $request, int $shift): JsonResponse
    {
        $control = $request->validate(['expected_revision' => 'required|integer|min:1', 'refresh_snapshot' => 'sometimes|boolean']);

        return $this->json(['shift' => $this->view($this->shifts->previewUpdate($request->user(), $shift, $this->shifts->validated($request->all()), (int) $control['expected_revision'], (bool) ($control['refresh_snapshot'] ?? false)))]);
    }

    public function update(Request $request, int $shift): JsonResponse
    {
        $control = $request->validate(['operation_id' => 'required|uuid', 'expected_revision' => 'required|integer|min:1', 'refresh_snapshot' => 'sometimes|boolean']);
        $input = $this->shifts->validated($request->all());
        $payload = ['id' => $shift, 'input' => $input, 'expected_revision' => (int) $control['expected_revision'], 'refresh_snapshot' => (bool) ($control['refresh_snapshot'] ?? false)];

        return $this->json($this->mutations->run($request->user(), $control['operation_id'], 'shift.update', $payload,
            fn ($user) => ['shift' => $this->view($this->shifts->update($user, $shift, $input, $payload['expected_revision'], $payload['refresh_snapshot']))]));
    }

    public function settings(Request $request): JsonResponse
    {
        $data = $request->validate(['month' => 'required|date_format:Y-m']);

        return $this->json($this->settings->show($request->user(), $data['month']));
    }

    public function updateSettings(Request $request, string $month): JsonResponse
    {
        $control = $request->validate(['operation_id' => 'required|uuid', 'expected_revision' => 'required|integer|min:0']);
        $settings = $this->settings->validateInput($month, $request->all());

        return $this->json($this->mutations->run($request->user(), $control['operation_id'], 'settings.update', ['month' => $month, 'settings' => $settings, 'expected_revision' => (int) $control['expected_revision']],
            fn ($user) => $this->settings->update($user, $month, $settings, (int) $control['expected_revision'])));
    }

    private function view(WorkShift $shift): array
    {
        return ['id' => $shift->id, 'revision' => $shift->revision, 'shift_date' => $shift->shift_date->toDateString(),
            'type' => $shift->type, 'absence_kind' => $shift->absence_kind, 'absence_end' => $shift->absence_end?->toDateString(),
            'started_at' => $shift->getAttributes()['started_at'], 'ended_at' => $shift->getAttributes()['ended_at'],
            'break_duration' => (int) $shift->break_duration, 'shift_code' => $shift->shift_code, 'route_id' => $shift->route_id,
            'start_location' => $shift->start_location, 'end_location' => $shift->end_location, 'segments' => $shift->segments,
            'calculation_snapshot' => $shift->calculation_snapshot, 'calculation_result' => $shift->calculation_result];
    }

    private function json(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status)->header('Cache-Control', 'no-store, private');
    }
}
