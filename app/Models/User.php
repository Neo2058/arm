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
        return in_array(
            $this->role?->value,
            [
                UserRole::STUDENT->value,
                UserRole::SUPER_ADMIN->value,
            ],
            true
        );
    }

    public function devices() { return $this->hasMany(UserDevice::class); }

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
