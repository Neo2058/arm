<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\TrainingMaterial;
use App\Models\TrainingTopic;
use App\Services\FileProxyService;
use App\Services\Training\TrainingContentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TrainingController extends Controller
{
    public function __construct(
        private TrainingContentService $content,
        private FileProxyService $files,
    ) {}

    public function topics(): JsonResponse
    {
        return response()->json([
            'topics' => $this->content->getAllTopics()->map(fn (TrainingTopic $topic) => [
                'id' => $topic->id,
                'title' => $topic->title,
                'slug' => $topic->slug,
                'description' => $topic->description,
            ])->values(),
        ]);
    }

    public function topic(TrainingTopic $topic): JsonResponse
    {
        abort_unless($topic->is_active, 404);

        $materials = $this->content->getMaterialsByTopic($topic->id)->map(fn (TrainingMaterial $material) => [
            'id' => $material->id,
            'title' => $material->title,
            'description' => $material->description,
            'type' => $material->type,
            'duration' => $material->duration,
            'content' => $material->type === 'text' ? $material->content : null,
        ]);

        return response()->json([
            'topic' => [
                'id' => $topic->id,
                'title' => $topic->title,
                'slug' => $topic->slug,
                'description' => $topic->description,
            ],
            'materials' => $materials->values(),
        ]);
    }

    public function material(TrainingMaterial $material): JsonResponse
    {
        abort_unless($material->is_active, 404);

        $url = null;
        if (in_array($material->type, ['video', 'audio'], true) && $material->file_path) {
            $url = $this->files->temporarySignedRoute(
                'mobile.files.training',
                15,
                $material->id
            );
        }

        $comments = $material->comments()->with('user:id,name')->latest()->take(50)->get()
            ->map(fn ($comment) => [
                'id' => $comment->id,
                'body' => $comment->comment,
                'author' => $comment->user?->name,
                'created_at' => $comment->created_at?->toIso8601String(),
            ]);

        return response()->json([
            'id' => $material->id,
            'title' => $material->title,
            'description' => $material->description,
            'type' => $material->type,
            'duration' => $material->duration,
            'content' => $material->type === 'text' ? $material->content : null,
            'url' => $url,
            'comments' => $comments->values(),
        ]);
    }

    public function storeComment(Request $request, TrainingMaterial $material): JsonResponse
    {
        abort_unless($material->is_active, 404);

        $validated = $request->validate([
            'comment' => 'required|string|max:1000',
        ]);

        $comment = $material->comments()->create([
            'user_id' => $request->user()->id,
            'comment' => $validated['comment'],
        ]);

        return response()->json([
            'id' => $comment->id,
            'body' => $comment->comment,
            'author' => $request->user()->name,
            'created_at' => $comment->created_at?->toIso8601String(),
        ], 201);
    }
}
