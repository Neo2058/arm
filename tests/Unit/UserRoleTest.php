<?php

namespace Tests\Unit;

use App\Enums\UserRole;
use PHPUnit\Framework\TestCase;

class UserRoleTest extends TestCase
{
    public function test_naryadchik_aliases_to_dispatcher(): void
    {
        $this->assertSame(UserRole::DISPATCHER, UserRole::safeFrom('naryadchik'));
        $this->assertSame(UserRole::DISPATCHER, UserRole::safeFrom('Naryadchik'));
        $this->assertSame(UserRole::DISPATCHER, UserRole::safeFrom('dispatcher'));
        $this->assertSame(UserRole::DISPATCHER, UserRole::safeFrom(UserRole::DISPATCHER));
    }

    public function test_is_admin_covers_admin_and_super_admin(): void
    {
        $this->assertTrue(UserRole::ADMIN->isAdmin());
        $this->assertTrue(UserRole::SUPER_ADMIN->isAdmin());
        $this->assertFalse(UserRole::DRIVER->isAdmin());
        $this->assertFalse(UserRole::DISPATCHER->isAdmin());
        $this->assertFalse(UserRole::INSTRUCTOR->isAdmin());
        $this->assertFalse(UserRole::STUDENT->isAdmin());
    }

    public function test_unknown_role_falls_back_to_student(): void
    {
        $this->assertSame(UserRole::STUDENT, UserRole::safeFrom('unknown'));
        $this->assertSame(UserRole::STUDENT, UserRole::safeFrom(null));
    }
}
