<?php

namespace App\Services;

use App\Models\Document;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class DocumentRevisions
{
    private function authorize(User $actor): void
    {
        $actor = $actor->fresh();
        abort_unless($actor && $actor->is_active && $actor->isAdmin(), 403);
        abort_unless(auth()->id() === $actor->id, 403);
    }

    public function publish(User $actor, int $sourceId, array $input): Document
    {
        $this->authorize($actor);
        if (is_string($input['revision_label'] ?? null)) {
            $input['revision_label'] = trim($input['revision_label']);
        }
        $data = Validator::make($input, [
            'revision_label' => 'required|string|max:255',
            'effective_on' => 'required|date|before_or_equal:today',
            'replacement_reason' => 'required|string|max:2000',
            'replacement_confirmed' => 'required|accepted',
            'references_reviewed' => 'required|accepted',
            'file_path' => 'required|string|max:255',
        ])->validate();

        return DB::transaction(function () use ($sourceId, $data, $input, $actor) {
            if (DB::getDriverName() === 'pgsql') {
                DB::select('SELECT pg_advisory_xact_lock(731209128)');
            }
            $source = Document::whereKey($sourceId)->lockForUpdate()->firstOrFail();
            $root = $source->root_document_id ?: $source->id;
            $family = Document::where(fn ($q) => $q->whereKey($root)->orWhere('root_document_id', $root));
            if (Document::where('replaces_document_id', $source->id)->exists()
                || (clone $family)->active()->whereKeyNot($source->id)->exists()) {
                throw ValidationException::withMessages(['revision_label' => 'Редакция уже сменилась. Откройте актуальную карточку.']);
            }
            if ((clone $family)->where('revision_label', $data['revision_label'])->exists()) {
                throw ValidationException::withMessages(['revision_label' => 'Обозначение редакции уже использовалось в этом документе.']);
            }
            $publisher = app(DocumentPublishing::class);
            $publisher->indexLegacy();
            $hash = $publisher->hash($data['file_path']);
            $publisher->assertUnique($hash);
            if (! $source->withdrawn_at) {
                $source->withdrawn_at = now();
                $source->withdrawn_by = $actor->id;
                $source->withdrawal_reason = $data['replacement_reason'];
                $source->save();
            }
            // Copy routing/permissions, never signatures, quiz answers or page references.
            $attributes = array_intersect_key($input, array_flip(['title', 'category', 'document_folder_id', 'document_number', 'publication_year', 'allowed_roles', 'instruction_category_id']));
            $attributes = array_merge($source->only(['title', 'category', 'document_folder_id', 'document_number', 'publication_year', 'allowed_roles', 'instruction_category_id']), $attributes);
            $revision = new Document($attributes);
            $revision->file_path = $data['file_path'];
            $revision->file_sha256 = $hash;
            $revision->revision_label = $data['revision_label'];
            $revision->effective_on = $data['effective_on'];
            $revision->root_document_id = $root;
            $revision->replaces_document_id = $source->id;
            $revision->revision_number = (int) (clone $family)->max('revision_number') + 1;
            $revision->save();

            return $revision;
        });
    }

    public function withdraw(User $actor, int $id, array $input): Document
    {
        $this->authorize($actor);
        $data = Validator::make($input, ['reason' => 'required|string|max:2000', 'confirmed' => 'required|accepted'])->validate();

        return DB::transaction(function () use ($id, $data, $actor) {
            if (DB::getDriverName() === 'pgsql') {
                DB::select('SELECT pg_advisory_xact_lock(731209128)');
            }
            $document = Document::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($document->withdrawn_at) {
                return $document;
            }
            $document->withdrawn_at = now();
            $document->withdrawn_by = $actor->id;
            $document->withdrawal_reason = $data['reason'];
            $document->save();

            return $document;
        });
    }
}
