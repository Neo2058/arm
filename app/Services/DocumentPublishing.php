<?php

namespace App\Services;

use App\Models\Document;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class DocumentPublishing
{
    public function hash(string $path): string
    {
        $stream = Storage::disk('s3')->readStream($path);
        if (! is_resource($stream)) {
            throw ValidationException::withMessages(['file_path' => 'Не удалось прочитать файл для проверки.']);
        }
        try {
            $context = hash_init('sha256');
            hash_update_stream($context, $stream);

            return hash_final($context);
        } finally {
            fclose($stream);
        }
    }

    public function assertUnique(string $hash, ?int $except = null): void
    {
        $match = Document::where('file_sha256', $hash)->when($except, fn ($q) => $q->whereKeyNot($except))->first();
        if ($match) {
            throw ValidationException::withMessages(['file_path' => 'Такой файл уже опубликован: документ №'.$match->id.' «'.$match->title.'». Используйте существующий документ.']);
        }
    }

    public function indexLegacy(): void
    {
        DB::transaction(function () {
            if (DB::getDriverName() === 'pgsql') {
                DB::select('SELECT pg_advisory_xact_lock(731209128)');
            }
            Document::whereNull('file_sha256')->orderBy('id')->chunkById(50, function ($records) {
                foreach ($records as $record) {
                    Document::whereKey($record->id)->update(['file_sha256' => $this->hash($record->file_path)]);
                }
            });
        });
    }

    public function save(array $data, ?Document $document = null): Document
    {
        return DB::transaction(function () use ($data, $document) {
            // Serialize publishing/indexing on PostgreSQL, including concurrent uploads.
            if (DB::getDriverName() === 'pgsql') {
                DB::select('SELECT pg_advisory_xact_lock(731209128)');
            }
            if ($document) {
                $document = Document::whereKey($document->id)->lockForUpdate()->firstOrFail();
                if ($document->withdrawn_at) {
                    throw ValidationException::withMessages(['title' => 'Архивная редакция неизменяема.']);
                }
                if (($data['file_path'] ?? $document->file_path) !== $document->file_path) {
                    throw ValidationException::withMessages(['file_path' => 'Замена файла требует новой редакции. Сохраните текущий оригинал и историю ознакомления.']);
                }
            }
            $path = $data['file_path'] ?? $document?->file_path;
            if (! is_string($path) || $path === '') {
                throw ValidationException::withMessages(['file_path' => 'Выберите файл документа.']);
            }
            $this->indexLegacy();
            $hash = $this->hash($path);
            if (! $document) {
                $this->assertUnique($hash);
            }
            unset($data['file_sha256']);
            $document ??= new Document;
            $document->fill($data);
            $document->file_sha256 = $hash;
            $document->save();

            return $document;
        });
    }
}
