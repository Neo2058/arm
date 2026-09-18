<?php

namespace App\Http\Controllers;

use App\Models\ActionLog;
use App\Models\TrainingMaterial;
use App\Models\TrainingTopic;
use App\Services\ClickHouseService;
use App\Services\FileProxyService;
use App\Services\Training\TrainingContentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TrainingController extends Controller
{
    public function __construct(
        protected TrainingContentService $service,
        protected FileProxyService $files,
    ) {}

    /**
     * Список тем обучения (дублирует /training из бота)
     */
    public function index()
    {
        $topics = $this->service->getAllTopics();

        return view('teaching.training.topics', compact('topics'));
    }

    /**
     * Материалы по теме (дублирует /topic {slug})
     */
    public function showTopic(TrainingTopic $topic)
    {
        $materials = $this->service->getMaterialsByTopic($topic->id);

        return view('teaching.training.topic', compact('topic', 'materials'));
    }

    /**
     * Страница просмотра видео
     */
    public function showVideo(TrainingMaterial $material)
    {
        $this->authorizeMaterial($material, 'video');

        $material->load('topic');

        $comments = $material->comments()->with('user')->latest()->take(50)->get();
        $likesCount = $material->likes_count;
        $dislikesCount = $material->dislikes_count;
        $userReaction = null;

        if (Auth::check()) {
            $userReaction = $material->reactions()
                ->where('user_id', Auth::id())
                ->value('reaction_type');
        }

        return view('teaching.training.video', compact('material', 'comments', 'likesCount', 'dislikesCount', 'userReaction'));
    }

    /**
     * Страница прослушивания аудио
     */
    public function showAudio(TrainingMaterial $material)
    {
        $this->authorizeMaterial($material, 'audio');

        $material->load('topic');

        $comments = $material->comments()->with('user')->latest()->take(50)->get();
        $likesCount = $material->likes_count;
        $dislikesCount = $material->dislikes_count;
        $userReaction = null;

        if (Auth::check()) {
            $userReaction = $material->reactions()
                ->where('user_id', Auth::id())
                ->value('reaction_type');
        }

        return view('teaching.training.audio', compact('material', 'comments', 'likesCount', 'dislikesCount', 'userReaction'));
    }

    private function authorizeMaterial(TrainingMaterial $material, string $expectedType)
    {
        abort_if($material->type !== $expectedType, 404);
        // В будущем добавить проверку доступа по ролям пользователя
    }

    /**
     * Stream video through the app (protected, inline, using signed temp link).
     * This hides direct S3 URLs and allows logging/protection.
     */
    public function streamVideo(TrainingMaterial $material)
    {
        $this->authorizeMaterial($material, 'video');

        return $this->streamMedia($material);
    }

    /**
     * Stream audio through the app.
     */
    public function streamAudio(TrainingMaterial $material)
    {
        $this->authorizeMaterial($material, 'audio');

        return $this->streamMedia($material);
    }

    private function streamMedia(TrainingMaterial $material)
    {
        $path = $material->file_path;

        if (! $this->files->exists($path)) {
            abort(404, 'Файл не найден');
        }

        // Log the view
        ActionLog::log('training_material_viewed', [
            'material_id' => $material->id,
            'title' => $material->title,
            'type' => $material->type,
        ]);

        // Also to ClickHouse
        ClickHouseService::log('training_material_viewed', $material->id, [
            'title' => $material->title,
            'type' => $material->type,
        ]);

        $filename = $material->file_name ?: basename($path);
        $mime = $material->mime_type ?: $this->files->disk()->mimeType($path) ?: ($material->type === 'video' ? 'video/mp4' : 'audio/mpeg');

        return $this->files->respond($path, $filename, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline; filename="'.addslashes($filename).'"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => '0',
            'X-Content-Type-Options' => 'nosniff',
            'Accept-Ranges' => 'bytes',  // for video seeking
        ]);
    }

    /**
     * Добавить комментарий к материалу
     */
    public function storeComment(Request $request, TrainingMaterial $material)
    {
        $request->validate([
            'comment' => 'required|string|max:1000',
        ]);

        $material->comments()->create([
            'user_id' => Auth::id(),
            'comment' => $request->comment,
        ]);

        return back()->with('success', 'Комментарий добавлен.');
    }

    /**
     * Поставить/сменить реакцию
     */
    public function storeReaction(Request $request, TrainingMaterial $material)
    {
        $request->validate([
            'type' => 'required|in:like,dislike',
        ]);

        $userId = Auth::id();

        $existing = $material->reactions()->where('user_id', $userId)->first();

        if ($existing) {
            if ($existing->reaction_type === $request->type) {
                // Убрать реакцию при повторном клике
                $existing->delete();
            } else {
                // Сменить реакцию
                $existing->update(['reaction_type' => $request->type]);
            }
        } else {
            $material->reactions()->create([
                'user_id' => $userId,
                'reaction_type' => $request->type,
            ]);
        }

        return back();
    }

    /**
     * Admin-only serve for training material files by path (for FileUpload preview in form).
     * Proxies from MinIO so browser can fetch without CORS/private address issues.
     */
    public function adminServeTrainingMaterial()
    {
        $path = request('path');
        if (empty($path)) {
            abort(404);
        }

        $user = auth()->user();

        if (! $user?->isAdmin()) {
            abort(403);
        }

        if (! $this->files->exists($path)) {
            abort(404, 'Файл не найден');
        }

        $filename = basename($path);
        $mime = $this->files->disk()->mimeType($path) ?: ($path ? (str_contains($path, 'video') ? 'video/mp4' : 'audio/mpeg') : 'application/octet-stream');

        return $this->files->respond($path, $filename, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline; filename="'.addslashes($filename).'"',
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
