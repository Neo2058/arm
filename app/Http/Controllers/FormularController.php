<?php

namespace App\Http\Controllers;

use App\Services\FormularService;
use Illuminate\Http\Request;

class FormularController extends Controller
{
    public function index(Request $request, FormularService $service)
    {
        $actor = $service->member($request->user());
        $review = $request->boolean('review');
        abort_if($review && ! $actor->isInstructor(), 403);
        $data = $review ? $service->review($actor, $request->all()) : $service->own($actor);

        return response()->view('teaching.formular', compact('data', 'review', 'actor'))->header('Cache-Control', 'private, no-store');
    }

    public function submit(Request $request, int $task, FormularService $service)
    {
        $service->mutate($request->user(), 'submit', $task, $request->all());

        return redirect()->route('formular.index')->with('success', 'Запись отправлена инструктору.');
    }

    public function review(Request $request, int $log, FormularService $service)
    {
        $data = $request->validate(['decision' => 'required|in:confirmed,returned']);
        $service->mutate($request->user(), $data['decision'], $log, $request->all());

        return redirect()->route('formular.index', ['review' => 1])->with('success', 'Результат проверки сохранён.');
    }
}
