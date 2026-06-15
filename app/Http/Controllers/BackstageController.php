<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\BackstageMessage;
use App\Services\TelegramService;
use App\Services\YookassaService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class BackstageController extends Controller
{
    /**
     * Страница Backstage / Связь с разработчиком
     */
    public function index()
    {
        $user = Auth::user();
        return view('backstage.index', compact('user'));
    }

    /**
     * Сохранение сообщения из формы Backstage (обратная связь + донат)
     */
    public function store(Request $request)
    {
        $request->validate([
            'message' => 'required|string|max:2000',
            'attachment' => 'nullable|image|max:5120', // до 5 МБ
            'support_amount' => 'nullable|numeric|min:10|max:100000',
            'name' => 'nullable|string|max:255',
        ]);

        $user = Auth::user();
        $path = null;

        if ($request->hasFile('attachment')) {
            $path = $request->file('attachment')->store('backstage', 's3');
        }

        $name = $request->name ?: $user->name;

        $message = BackstageMessage::create([
            'user_id' => $user->id,
            'name' => $name,
            'message' => $request->message,
            'attachment_path' => $path,
            'support_amount' => $request->support_amount,
            'source' => 'backstage',
        ]);

        // Уведомление в Telegram
        $amountText = $request->support_amount ? "💰 *Поддержка проекта:* {$request->support_amount} ₽\n" : '';
        $msg = "📨 *НОВОЕ СООБЩЕНИЕ ИЗ BACKSTAGE*\n\n";
        $msg .= "👤 От: *{$name}* (" . ($user->role->value ?? $user->role) . ")\n";
        $msg .= $amountText;
        $msg .= "📝 Сообщение:\n_{$request->message}_";

        if ($path) {
            TelegramService::sendPhoto($msg, $path);
        } else {
            TelegramService::send($msg);
        }

        return response()->json([
            'status' => 'success',
            'message' => $request->support_amount
                ? 'Спасибо огромное за поддержку проекта! Сообщение разработчику отправлено.'
                : 'Спасибо! Ваше сообщение отправлено разработчику.'
        ]);
    }

    /**
     * Быстрая поддержка без сообщения (отдельный эндпоинт для кнопок донатов)
     * Теперь создаёт платёж через ЮKassa и возвращает confirmation_url для редиректа.
     */
    public function support(Request $request, YookassaService $yookassa)
    {
        $request->validate([
            'amount' => 'required|numeric|min:10|max:100000',
        ]);

        $user = Auth::user();
        $amount = $request->amount;

        // Создаём запись заранее (с pending)
        $message = BackstageMessage::create([
            'user_id' => $user->id,
            'name' => $user->name,
            'message' => 'Поддержка проекта (донат через ЮKassa)',
            'support_amount' => $amount,
            'source' => 'backstage-support',
            'payment_status' => 'pending',
        ]);

        try {
            $payment = $yookassa->createPayment(
                $amount,
                "Поддержка проекта ARM от {$user->name}",
                [
                    'backstage_message_id' => $message->id,
                    'user_id' => $user->id,
                ],
                route('backstage.index') . '?payment=success'
            );

            // Сохраняем payment_id
            $message->update([
                'yookassa_payment_id' => $payment['payment_id'],
                'payment_status' => $payment['status'] ?? 'pending',
            ]);

            // Уведомляем разработчика о намерении
            $msg = "💖 *НОВЫЙ ДЕНАТ BACKSTAGE (через ЮKassa)*\n\n";
            $msg .= "👤 От: *{$user->name}*\n";
            $msg .= "💰 Сумма: *{$amount} ₽*\n";
            $msg .= "🆔 Payment ID: {$payment['payment_id']}\n";
            $msg .= "Статус: ожидает оплаты";

            TelegramService::send($msg);

            return response()->json([
                'status' => 'success',
                'confirmation_url' => $payment['confirmation_url'],
                'message' => 'Перенаправляем на страницу оплаты ЮKassa...'
            ]);

        } catch (\Exception $e) {
            Log::error('Yookassa payment error: ' . $e->getMessage());

            // Fallback: сохраняем без оплаты
            return response()->json([
                'status' => 'error',
                'message' => 'Не удалось создать платёж. Пожалуйста, попробуйте позже или свяжитесь с разработчиком напрямую.',
            ], 500);
        }
    }

    /**
     * Webhook для ЮKassa (должен быть доступен публично).
     * Добавьте в .env YOOKASSA_WEBHOOK_SECRET если используете дополнительную проверку.
     */
    public function yookassaWebhook(Request $request, YookassaService $yookassa)
    {
        $data = $request->all();

        // Простая проверка (в продакшене используйте подпись или IP whitelist)
        if (empty($data['object']['id'])) {
            return response()->json(['error' => 'Invalid payload'], 400);
        }

        $verifiedPayment = $yookassa->verifyAndGetPayment($data);

        if (!$verifiedPayment || empty($verifiedPayment['id'])) {
            Log::warning('Yookassa webhook verification failed', $data);
            return response()->json(['error' => 'Verification failed'], 400);
        }

        $paymentId = $verifiedPayment['id'];
        $status = $verifiedPayment['status'] ?? 'pending';

        $message = BackstageMessage::where('yookassa_payment_id', $paymentId)->first();

        if ($message) {
            $message->payment_status = $status;

            if ($status === 'succeeded') {
                $message->paid_at = now();
            }

            $message->save();

            // Уведомление об успешной оплате
            if ($status === 'succeeded') {
                $msg = "✅ *ОПЛАТА УСПЕШНА (BACKSTAGE)*\n\n";
                $msg .= "👤 {$message->name}\n";
                $msg .= "💰 {$message->support_amount} ₽\n";
                $msg .= "🆔 {$paymentId}";

                TelegramService::send($msg);
            }
        }

        // ЮKassa ожидает 200 OK
        return response()->json(['success' => true]);
    }
}
