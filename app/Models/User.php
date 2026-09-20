<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements FilamentUser
{
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'role' => UserRole::class,
        'is_active' => 'boolean',
    ];

    public function profile()
    {
        return $this->hasOne(UserProfile::class);
    }

    public function personnel()
    {
        return $this->hasOne(ArmPersonnel::class);
    }

    public function roleEnum(): UserRole
    {
        return UserRole::safeFrom($this->role ?? '');
    }

    public function roleValue(): string
    {
        return $this->roleEnum()->value;
    }

    /**
     * Значения роли для JSON-доступов. dispatcher также матчит legacy naryadchik.
     *
     * @return list<string>
     */
    public function roleValuesForAccess(): array
    {
        $value = $this->roleValue();

        return $value === UserRole::DISPATCHER->value
            ? [UserRole::DISPATCHER->value, 'naryadchik']
            : [$value];
    }

    public function isInstructor(): bool
    {
        return $this->roleEnum() === UserRole::INSTRUCTOR;
    }

    public function isAdmin(): bool
    {
        return $this->roleEnum()->isAdmin();
    }

    public function isSuperAdmin(): bool
    {
        return $this->roleEnum() === UserRole::SUPER_ADMIN;
    }

    public function isDispatcher(): bool
    {
        return $this->roleEnum() === UserRole::DISPATCHER;
    }

    public function isDriver(): bool
    {
        return $this->roleEnum() === UserRole::DRIVER;
    }

    public function isStudent(): bool
    {
        return $this->roleEnum() === UserRole::STUDENT;
    }

    public function canBypassAccessBarriers(): bool
    {
        return $this->isAdmin();
    }

    public function canViewRospisiStatistics(): bool
    {
        return $this->isAdmin() || $this->isInstructor();
    }

    public function canAccessByRoles(?array $allowedRoles): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        if ($allowedRoles === null) {
            return true;
        }

        return count(array_intersect($this->roleValuesForAccess(), $allowedRoles)) > 0;
    }

    public function constrainByAllowedRoles($query)
    {
        if ($this->isAdmin()) {
            return $query;
        }

        return $query->where(function ($q) {
            $q->whereNull('allowed_roles');
            foreach ($this->roleValuesForAccess() as $role) {
                $q->orWhereJsonContains('allowed_roles', $role);
            }
        });
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->isAdmin();
    }

    public function devices()
    {
        return $this->hasMany(UserDevice::class);
    }

    public function podstroikas()
    {
        return $this->hasMany(Podstroika::class);
    }

    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',        // Проверь наличие этого поля
        'is_active',   // И этого, если добавляли блокировку
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
}
