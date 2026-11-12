<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Services\InstructionSignatures;
use Illuminate\Http\Request;

class InstructionController extends Controller
{
    public function index(Request $request, InstructionSignatures $service)
    {
        return response()->json(['categories' => $service->categories($request->user())])->header('Cache-Control', 'private, no-store');
    }

    public function sign(Request $request, int $document, InstructionSignatures $service)
    {
        return response()->json($service->sign($request->user(), $document, $request->all()))->header('Cache-Control', 'private, no-store');
    }
}
