<?php

namespace App\Enums;

enum UserRole: string
{
    case SUPER_ADMIN = 'super_admin';

    case ADMIN = 'admin';

    case INSTRUCTOR = 'instructor';

    case STUDENT = 'student';

    case DRIVER = 'driver';

    case DISPATCHER = 'dispatcher';

    case OPERATOR = 'operator';

    public function label(): string
    {
        return match ($this) {

            self::SUPER_ADMIN => 'Создатель',

            self::ADMIN => 'Зам начальника',

            self::INSTRUCTOR => 'Инструктор',

            self::STUDENT => 'Инструктор по обучению',

            self::DRIVER => 'Машинист',

            self::DISPATCHER => 'Нарядчик',

            self::OPERATOR => 'Оператор учёта',

            default => 'Неизвестная роль',
        };
    }

    public function color(): string
    {
        return match ($this) {

            self::SUPER_ADMIN, self::STUDENT => 'gray',

            self::ADMIN => 'warning',

            self::INSTRUCTOR => 'success',

            self::DRIVER => 'info',

            self::DISPATCHER => 'danger',

            self::OPERATOR => 'warning',
        };
    }

    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn ($role) => [
                $role->value => $role->label(),
            ])
            ->toArray();
    }

    public function isAdmin(): bool
    {
        return $this === self::ADMIN || $this === self::SUPER_ADMIN;
    }

    public function isDispatcher(): bool
    {
        return $this === self::DISPATCHER;
    }

    public function isOperator(): bool
    {
        return $this === self::OPERATOR;
    }

    public static function safeFrom(self|string|null $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        $normalized = strtolower(trim((string) $value));

        // Legacy alias: в UI/коде раньше фигурировал naryadchik, в БД роль всегда dispatcher.
        if ($normalized === 'naryadchik') {
            return self::DISPATCHER;
        }

        return self::tryFrom($normalized) ?? self::STUDENT;
    }
}
