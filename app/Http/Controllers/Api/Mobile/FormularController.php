<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Services\FormularService;
use Illuminate\Http\Request;

class FormularController extends Controller
{
    public function __construct(private FormularService $service) {}

    private function json(array $data)
    {
        return response()->json($data)->header('Cache-Control', 'private, no-store');
    }

    public function index(Request $request)
    {
        return $this->json($this->service->own($request->user()));
    }

    public function review(Request $request)
    {
        return $this->json($this->service->review($request->user(), $request->all()));
    }

    public function submit(Request $request, int $task)
    {
        return $this->json($this->service->mutate($request->user(), 'submit', $task, $request->all()));
    }

    public function decide(Request $request, int $log)
    {
        $data = $request->validate(['decision' => 'required|in:confirmed,returned']);

        return $this->json($this->service->mutate($request->user(), $data['decision'], $log, $request->all()));
    }
}
