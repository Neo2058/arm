<?php

namespace App\Services;

use App\Models\PhoneContact;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

class PhoneDirectory
{
    public static function normalize(string $value): ?string
    {
        $value = trim($value);
        if (! preg_match('/^\+?[0-9\s().-]+$/u', $value)) {
            return null;
        }
        $digits = preg_replace('/\D/', '', $value);
        if (! str_starts_with($value, '+')) {
            if (strlen($digits) === 10) {
                $digits = '7'.$digits;
            } elseif (strlen($digits) === 11 && str_starts_with($digits, '8')) {
                $digits = '7'.substr($digits, 1);
            }
        }
        if (preg_match('/^7[0-9]{10}$/', $digits)) {
            return '+'.$digits;
        }

        return str_starts_with($value, '+') && preg_match('/^[1-9][0-9]{7,14}$/', $digits) ? '+'.$digits : null;
    }

    public function create(string $name, string $phone, User $actor): PhoneContact
    {
        $normalized = self::normalize($phone);
        if (! $normalized) {
            throw ValidationException::withMessages(['phone' => 'Введите полный номер: +7 XXX XXX-XX-XX или международный номер с кодом страны и знаком +.']);
        }
        try {
            $contact = PhoneContact::create(['name' => preg_replace('/\s+/u', ' ', trim($name)), 'phone' => $normalized, 'created_by' => $actor->id]);
        } catch (UniqueConstraintViolationException $e) {
            throw ValidationException::withMessages(['phone' => 'Этот номер уже есть в общем справочнике. Найдите его через поиск.']);
        }
        ClickHouseService::log('phone.created', $contact->id, ['id' => $contact->id, 'actor_id' => $actor->id], $actor);

        return $contact;
    }
}
