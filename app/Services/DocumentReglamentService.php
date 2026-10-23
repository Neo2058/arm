<?php

namespace App\Services;

use App\Models\ActionLog;
use App\Models\Document;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class DocumentReglamentService
{
    /**
     * Capture / export attempts the mobile reader may report.
     *
     * @var list<string>
     */
    public const KINDS = ['screenshot', 'recording', 'download', 'share', 'print'];

    public static function actionForKind(string $kind): string
    {
        return in_array($kind, ['screenshot', 'recording'], true)
            ? 'violation_document_screenshot'
            : 'violation_document_download';
    }

    public static function report(User $user, Document $document, string $kind, string $via = 'mobile'): void
    {
        $userRole = $user->roleValue();
        $action = self::actionForKind($kind);

        ActionLog::violation($action, [
            'document_id' => $document->id,
            'title' => $document->title,
            'user_role' => $userRole,
            'via' => $via,
            'kind' => $kind,
        ]);

        try {
            AdminNotificationService::notify(
                'violation',
                self::notifyTitle($kind),
                self::notifyBody($user, $document, $kind, $userRole),
                [
                    'user_id' => $user->id,
                    'document_id' => $document->id,
                    'action' => $kind === 'download' ? 'download_attempt' : $kind,
                    'via' => $via,
                ]
            );

            $tgMessage = "🚨 *Нарушение регламента использования документов*\n";
            $tgMessage .= "👤 Пользователь: {$user->name} (ID: {$user->id}, роль: {$userRole})\n";
            $tgMessage .= "📄 Документ: {$document->title} (ID: {$document->id})\n";
            $tgMessage .= '📍 Действие: '.self::kindLabel($kind)."\n";
            if ($via !== 'web') {
                $tgMessage .= "📱 Канал: {$via}\n";
            }
            $tgMessage .= '⏰ '.now()->format('Y-m-d H:i:s');

            TelegramService::send($tgMessage, null, 'Markdown');
        } catch (\Throwable $e) {
            Log::error('Failed to send document reglament alerts', ['error' => $e->getMessage()]);
        }
    }

    public static function kindLabel(string $kind): string
    {
        return match ($kind) {
            'screenshot' => 'попытка снимка экрана',
            'recording' => 'попытка записи экрана',
            'share' => 'попытка отправки документа',
            'print' => 'попытка печати',
            default => 'попытка скачивания (запрещено, только просмотр)',
        };
    }

    private static function notifyTitle(string $kind): string
    {
        return match ($kind) {
            'screenshot' => 'Нарушение регламента: попытка снимка экрана',
            'recording' => 'Нарушение регламента: попытка записи экрана',
            'share' => 'Нарушение регламента: попытка отправки документа',
            'print' => 'Нарушение регламента: попытка печати документа',
            default => 'Нарушение регламента: попытка скачивания документа',
        };
    }

    private static function notifyBody(User $user, Document $document, string $kind, string $userRole): string
    {
        $who = "Пользователь {$user->name} (роль: {$userRole})";
        $doc = "документ «{$document->title}» (ID: {$document->id})";

        return match ($kind) {
            'screenshot' => "{$who} попытался сделать снимок экрана: {$doc}.",
            'recording' => "{$who} попытался записать экран: {$doc}.",
            'share' => "{$who} попытался отправить {$doc}.",
            'print' => "{$who} попытался напечатать {$doc}.",
            default => "{$who} попытался скачать {$doc}. Доступно только чтение.",
        };
    }
}
