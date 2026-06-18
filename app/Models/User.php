<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Filament\Panel;
use App\Enums\UserRole;

class User extends Authenticatable implements FilamentUser
{
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'role' => UserRole::class,
    ];
    public function profile() {
        return $this->hasOne(UserProfile::class);
    }
    public function canAccessPanel(Panel $panel): bool {
        // Support both enum and string (in case of casting issues or legacy data)
        $roleValue = $this->role instanceof UserRole
            ? $this->role->value
            : strtolower((string) ($this->role ?? ''));

        // Only super admins and (regular) admins are allowed to access the Filament admin panel.
        // Previously restricted to only student + super_admin, which broke login for admin users.
        return in_array($roleValue, [
            UserRole::SUPER_ADMIN->value,
            UserRole::ADMIN->value,
        ], true);
    }

    public function devices() { return $this->hasMany(UserDevice::class); }

    public function podstroikas() { return $this->hasMany(Podstroika::class); }

    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

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
