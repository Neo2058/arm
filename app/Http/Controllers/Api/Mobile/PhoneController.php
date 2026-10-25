<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\PhoneContact;
use App\Services\PhoneDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PhoneController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['q' => 'nullable|string|max:160', 'page' => 'nullable|integer|min:1']);
        $q = trim($data['q'] ?? '');
        $contacts = PhoneContact::query();
        if ($q !== '') {
            $pattern = '%'.addcslashes($q, '%_\\').'%';
            $operator = $contacts->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
            $digits = preg_replace('/\D/', '', $q);
            $contacts->where(function ($query) use ($pattern, $operator, $digits) {
                $query->where('name', $operator, $pattern)->orWhere('phone', 'like', $pattern);
                if ($operator === 'like') {
                    $query->orWhere('name', 'like', mb_strtoupper($pattern))->orWhere('name', 'like', mb_strtolower($pattern));
                }
                if (strlen($digits) >= 3) {
                    $query->orWhere('phone', 'like', '%'.ltrim(PhoneDirectory::normalize($digits) ?? $digits, '+').'%');
                }
            });
        }
        $page = $contacts->orderBy('name')->orderBy('id')->paginate(100);

        return response()->json([
            'phones' => $page->getCollection()->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'phone' => $p->phone, 'phone2' => null, 'position' => null])->values(),
            'page' => $page->currentPage(), 'has_more' => $page->hasMorePages(),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function store(Request $request, PhoneDirectory $directory): JsonResponse
    {
        $data = $request->validate(['name' => 'required|string|max:160|regex:/\p{L}/u', 'phone' => 'required|string|max:40']);
        $contact = $directory->create($data['name'], $data['phone'], $request->user());

        return response()->json(['phone' => ['id' => $contact->id, 'name' => $contact->name, 'phone' => $contact->phone]], 201)
            ->header('Cache-Control', 'no-store, private');
    }
}
