<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Services\FileProxyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DocumentController extends Controller
{
    private const CATEGORY_LABELS = [
        'manual' => 'Руководства по эксплуатации',
        'order' => 'Приказы и распоряжения по депо',
        'orderForMetro' => 'Приказы по Метрополитену',
        'instruction' => 'Инструкции по Депо',
        'instForMetro' => 'Инструкции по Метрополитену',
        'technical' => 'Конспекты УПЦ',
        'student' => 'Конспект ТУ',
        'other' => 'Прочие материалы',
        'remember' => 'Памятки',
    ];

    public function __construct(private FileProxyService $files) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = $user->constrainByAllowedRoles(Document::query());

        $categories = $query->orderBy('title')->get()->map(function (Document $document) {
            $rawCategory = $document->category ?: 'other';

            return [
                'id' => $document->id,
                'title' => $document->title,
                'category_key' => $rawCategory,
                'category_name' => self::CATEGORY_LABELS[$rawCategory] ?? $rawCategory,
                'updated_at' => $document->updated_at?->format('d.m.Y'),
            ];
        })
            ->groupBy('category_name')
            ->map(fn ($items, $name) => [
                'name' => $name,
                'items' => $items->values()->toArray(),
            ])
            ->values()
            ->toArray();

        return response()->json(['categories' => $categories]);
    }

    public function show(Request $request, Document $document): JsonResponse
    {
        if (! $request->user()->canAccessByRoles($document->allowed_roles)) {
            abort(403, 'Доступ к документу ограничен.');
        }

        $url = $this->files->temporarySignedRoute(
            'mobile.files.documents',
            30,
            $document->id
        );

        return response()->json([
            'id' => $document->id,
            'title' => $document->title,
            'url' => $url,
        ]);
    }
}
