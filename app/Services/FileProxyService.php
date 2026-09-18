<?php

namespace App\Services;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Единственная точка стрима приватных файлов из MinIO через приложение.
 *
 * Нельзя:
 * - отдавать Storage::temporaryUrl() / прямые MinIO URL в браузер;
 * - менять имена роутов, параметры signed URL и TTL в вызывающем коде;
 * - трогать Filament FileUpload (диск, directory, visibility, getUploadedFileUsing).
 *
 * Заголовки задаёт вызывающий код — у документов, стрима и admin-preview они различаются
 * специально (Accept-Ranges, Pragma/Expires). Не унифицировать.
 */
class FileProxyService
{
    public function disk(): Filesystem
    {
        return Storage::disk('s3');
    }

    public function exists(?string $path): bool
    {
        return filled($path) && $this->disk()->exists($path);
    }

    /**
     * @param  array<string, string>  $headers
     */
    public function respond(string $path, string $filename, array $headers): StreamedResponse
    {
        if (! $this->exists($path)) {
            abort(404, 'Файл не найден');
        }

        return $this->disk()->response($path, $filename, $headers);
    }

    public function temporarySignedRoute(string $name, int $minutes, mixed $parameters): string
    {
        return URL::temporarySignedRoute($name, now()->addMinutes($minutes), $parameters);
    }
}
