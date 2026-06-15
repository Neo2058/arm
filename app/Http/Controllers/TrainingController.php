<?php

namespace App\Http\Controllers;

use App\Models\TrainingMaterial;
use App\Models\TrainingTopic;
use App\Services\Training\TrainingContentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TrainingController extends Controller
{
    protected TrainingContentService $service;

    public function __construct(TrainingContentService $service)
    {
        $this->service = $service;
    }

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
}
