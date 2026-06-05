<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;


class RoleUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        User::create([
            'name' => 'Super Admin',
            'email' => 'super_admin@test.com',
            'password' => Hash::make('password'),
            'role' => UserRole::SUPER_ADMIN,
            'is_active' => true,
        ]);

        User::create([
            'name' => 'Admin',
            'email' => 'admin@test.com',
            'password' => Hash::make('password'),
            'role' => UserRole::ADMIN,
            'is_active' => true,
        ]);

        User::create([
            'name' => 'Instructor',
            'email' => 'instructor@test.com',
            'password' => Hash::make('password'),
            'role' => UserRole::INSTRUCTOR,
            'is_active' => true,
        ]);

        User::create([
            'name' => 'Driver',
            'email' => 'driver@test.com',
            'password' => Hash::make('password'),
            'role' => UserRole::DRIVER,
            'is_active' => true,
        ]);

        User::create([
            'name' => 'Student',
            'email' => 'student@test.com',
            'password' => Hash::make('password'),
            'role' => UserRole::STUDENT,
            'is_active' => true,
        ]);
    }
}
