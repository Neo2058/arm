<?php

namespace App\Services;

use App\Models\Document;
use App\Models\InstructionCategory;
use App\Models\User;
use App\Models\UserInstructionSignature;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class InstructionSignatures
{
    public function categories(User $actor): array
    {
        $actor = $actor->fresh();
        abort_unless($actor && $actor->is_active, 403);
        $signatures = UserInstructionSignature::where('user_id', $actor->id)->get()->keyBy('document_id');

        return InstructionCategory::where('is_active', true)->orderBy('sort_order')->orderBy('id')
            ->with(['documents' => function ($query) use ($actor) {
                $actor->constrainByAllowedRoles($query)->with('quiz')->orderBy('title')->orderBy('id');
            }])->get()->map(function ($category) use ($signatures) {
                return ['id' => $category->id, 'name' => $category->name, 'documents' => $category->documents->map(function ($doc) use ($signatures) {
                    $signature = $signatures->get($doc->id);
                    $quiz = $doc->quiz;

                    return ['id' => $doc->id, 'title' => $doc->title, 'signed' => (bool) $signature?->signed_at,
                        'signed_at' => $signature?->signed_at?->toIso8601String(),
                        'has_file' => filled($doc->file_path), 'quiz_id' => $quiz?->is_active ? $quiz->id : null];
                })->all()];
            })->filter(fn ($category) => count($category['documents']) > 0)->values()->all();
    }

    public function sign(User $actor, int $id, array $input): array
    {
        Validator::make($input, ['confirmed' => 'required|accepted', 'notes' => 'nullable|string|max:2000'])->validate();

        return DB::transaction(function () use ($actor, $id, $input) {
            $actor = User::whereKey($actor->id)->lock(DB::getDriverName() === 'pgsql' ? 'for no key update' : true)->firstOrFail();
            abort_unless($actor->is_active, 403);
            $document = Document::whereHas('instructionCategory', fn ($q) => $q->where('is_active', true))->sharedLock()->findOrFail($id);
            abort_unless($actor->canAccessByRoles($document->allowed_roles), 403);
            $signature = UserInstructionSignature::where('user_id', $actor->id)->where('document_id', $id)->lockForUpdate()->first();
            if ($signature?->signed_at) {
                return $this->payload($signature);
            }
            abort_unless(app(FileProxyService::class)->exists($document->file_path), 404, 'Файл документа недоступен.');
            $signature ??= new UserInstructionSignature(['user_id' => $actor->id, 'document_id' => $id]);
            $signature->instruction_category_id = $document->instruction_category_id;
            $signature->signed_at = now();
            $signature->notes = $input['notes'] ?? null;
            $signature->save();
            DB::afterCommit(function () use ($actor, $document) {
                try {
                    ClickHouseService::log('instruction_signed', $document->id, ['user_id' => $actor->id], $actor);
                } catch (\Throwable $error) {
                    report($error);
                }
            });

            return $this->payload($signature);
        }, 3);
    }

    private function payload(UserInstructionSignature $signature): array
    {
        return ['document_id' => $signature->document_id, 'signed' => true, 'signed_at' => $signature->signed_at->toIso8601String()];
    }
}
