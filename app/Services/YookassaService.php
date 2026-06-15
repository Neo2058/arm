<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class YookassaService
{
    protected string $shopId;
    protected string $secretKey;
    protected string $apiUrl = 'https://api.yookassa.ru/v3';

    public function __construct()
    {
        $this->shopId = config('services.yookassa.shop_id', env('YOOKASSA_SHOP_ID'));
        $this->secretKey = config('services.yookassa.secret_key', env('YOOKASSA_SECRET_KEY'));
    }

    public function isConfigured(): bool
    {
        return !empty($this->shopId) && !empty($this->secretKey);
    }

    /**
     * Create a payment and return the confirmation URL + payment id.
     */
    public function createPayment(float $amount, string $description, array $metadata = [], string $returnUrl = null): array
    {
        if (!$this->isConfigured()) {
            throw new \Exception('Yookassa is not configured. Add YOOKASSA_SHOP_ID and YOOKASSA_SECRET_KEY to .env');
        }

        $returnUrl = $returnUrl ?: url('/backstage?payment=success');

        $idempotenceKey = Str::uuid()->toString();

        $payload = [
            'amount' => [
                'value' => number_format($amount, 2, '.', ''),
                'currency' => 'RUB',
            ],
            'confirmation' => [
                'type' => 'redirect',
                'return_url' => $returnUrl,
            ],
            'capture' => true,
            'description' => $description,
            'metadata' => $metadata,
        ];

        $response = Http::withBasicAuth($this->shopId, $this->secretKey)
            ->withHeaders([
                'Idempotence-Key' => $idempotenceKey,
                'Content-Type' => 'application/json',
            ])
            ->post($this->apiUrl . '/payments', $payload);

        if ($response->failed()) {
            throw new \Exception('Yookassa payment creation failed: ' . $response->body());
        }

        $data = $response->json();

        return [
            'payment_id' => $data['id'],
            'confirmation_url' => $data['confirmation']['confirmation_url'] ?? null,
            'status' => $data['status'],
        ];
    }

    /**
     * Get payment status (for webhook verification or polling).
     */
    public function getPayment(string $paymentId): array
    {
        if (!$this->isConfigured()) {
            throw new \Exception('Yookassa not configured');
        }

        $response = Http::withBasicAuth($this->shopId, $this->secretKey)
            ->get($this->apiUrl . "/payments/{$paymentId}");

        if ($response->failed()) {
            throw new \Exception('Failed to fetch payment: ' . $response->body());
        }

        return $response->json();
    }

    /**
     * Basic webhook verification (Yookassa recommends checking the payment via API).
     * For production, also validate IP ranges if needed.
     */
    public function verifyAndGetPayment(array $webhookData): ?array
    {
        if (empty($webhookData['object']['id'])) {
            return null;
        }

        $paymentId = $webhookData['object']['id'];

        // Always re-fetch from API for security
        try {
            return $this->getPayment($paymentId);
        } catch (\Exception $e) {
            \Log::error('Yookassa webhook verification failed: ' . $e->getMessage());
            return null;
        }
    }
}
