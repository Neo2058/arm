<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\ArmPersonnel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PhoneController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        $people = ArmPersonnel::query()
            ->where(function ($query) {
                $query->whereNull('fired_on')
                    ->orWhereDate('fired_on', '>=', now()->toDateString());
            })
            ->where(function ($query) {
                $query->where(function ($inner) {
                    $inner->whereNotNull('phone_primary')->where('phone_primary', '!=', '');
                })->orWhere(function ($inner) {
                    $inner->whereNotNull('phone_secondary')->where('phone_secondary', '!=', '');
                });
            })
            ->when($q !== '', fn ($query) => $this->constrainSearch($query, $q))
            ->orderBy('full_name')
            ->limit(200)
            ->get(['id', 'full_name', 'phone_primary', 'phone_secondary', 'position_code']);

        return response()->json([
            'phones' => $people->map(fn (ArmPersonnel $person) => [
                'id' => $person->id,
                'name' => $person->full_name,
                'phone' => $person->phone_primary,
                'phone2' => $person->phone_secondary,
                'position' => $person->position_code,
            ])->values(),
        ]);
    }

    private function constrainSearch($query, string $q)
    {
        $pattern = '%'.addcslashes($q, '%_\\').'%';
        $columns = ['full_name', 'phone_primary', 'phone_secondary'];

        return $query->where(function ($inner) use ($pattern, $q, $columns) {
            if ($inner->getConnection()->getDriverName() === 'pgsql') {
                foreach ($columns as $index => $column) {
                    $index === 0
                        ? $inner->where($column, 'ilike', $pattern)
                        : $inner->orWhere($column, 'ilike', $pattern);
                }

                return;
            }

            $variants = array_values(array_unique([
                $pattern,
                '%'.addcslashes(mb_strtolower($q), '%_\\').'%',
                '%'.addcslashes(mb_strtoupper($q), '%_\\').'%',
            ]));

            $first = true;
            foreach ($columns as $column) {
                foreach ($variants as $variant) {
                    if ($first) {
                        $inner->where($column, 'like', $variant);
                        $first = false;
                    } else {
                        $inner->orWhere($column, 'like', $variant);
                    }
                }
            }
        });
    }
}
