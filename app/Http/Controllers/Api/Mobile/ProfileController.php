<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Services\SelfProfile;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function show(Request $request, SelfProfile $profiles)
    {
        return response()->json($profiles->payload($request->user()))->header('Cache-Control', 'no-store, private');
    }

    public function update(Request $request, SelfProfile $profiles)
    {
        return response()->json($profiles->update($request->user(), $request->all()))->header('Cache-Control', 'no-store, private');
    }
}
