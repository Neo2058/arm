<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Http\Middleware\CheckDeviceBinding;
use App\Http\Middleware\CheckDynamicBarrier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class JournalAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([
            CheckDeviceBinding::class,
            CheckDynamicBarrier::class,
        ]);
    }

    public static function journalEndpoints(): array
    {
        return [
            'index' => ['get', '/journal'],
            'settings' => ['get', '/journal/settings'],
            'standards' => ['get', '/journal/standards'],
            'history' => ['get', '/journal/history'],
            'report' => ['get', '/journal/report'],
            'naryad-search' => ['get', '/journal/naryad-search'],
            'todo.add' => ['post', '/journal/todo', ['title' => 'Тестовая задача']],
            'todo.complete' => ['post', '/journal/todo/1/complete'],
            'todo.update' => ['post', '/journal/todo/1/update', ['title' => 'Обновлённая']],
            'todo.destroy' => ['delete', '/journal/todo/1'],
            'document.upload' => ['post', '/journal/document', ['title' => 'Док']],
            'ask' => ['post', '/journal/ask', ['document_id' => 1, 'question' => 'Что делать?']],
            'settings.update' => ['post', '/journal/settings', []],
            'standards.update' => ['post', '/journal/standards', ['normatives' => []]],
            'vacation.add' => ['post', '/journal/report/vacation', [
                'user_id' => 1,
                'start_date' => '2026-01-01',
                'end_date' => '2026-01-10',
            ]],
            'vacation.delete' => ['delete', '/journal/report/vacation/1'],
        ];
    }

    public static function forbiddenRoles(): array
    {
        return [
            'driver' => [UserRole::DRIVER],
            'admin' => [UserRole::ADMIN],
            'super_admin' => [UserRole::SUPER_ADMIN],
            'student' => [UserRole::STUDENT],
            'dispatcher' => [UserRole::DISPATCHER],
        ];
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/journal')->assertRedirect(route('login'));
        $this->post('/journal/todo', ['title' => 'x'])->assertRedirect(route('login'));
        $this->get('/journal/standards')->assertRedirect(route('login'));
    }

    public function test_instructor_can_open_journal_pages(): void
    {
        $instructor = User::factory()->instructor()->create();

        $this->actingAs($instructor)
            ->get('/journal')
            ->assertOk();

        $this->actingAs($instructor)
            ->get('/journal/settings')
            ->assertOk();

        $this->actingAs($instructor)
            ->get('/journal/naryad-search')
            ->assertOk();

        $this->actingAs($instructor)
            ->get('/journal/history')
            ->assertOk();
    }

    #[DataProvider('journalEndpoints')]
    public function test_driver_cannot_access_any_journal_endpoint(string $method, string $uri, array $payload = []): void
    {
        $driver = User::factory()->driver()->create();

        $this->actingAs($driver)
            ->{$method}($uri, $payload)
            ->assertForbidden();
    }

    #[DataProvider('forbiddenRoles')]
    public function test_non_instructor_cannot_open_journal(UserRole $role): void
    {
        $user = User::factory()->create(['role' => $role]);

        $this->actingAs($user)
            ->get('/journal')
            ->assertForbidden();
    }

    #[DataProvider('forbiddenRoles')]
    public function test_non_instructor_cannot_write_standards(UserRole $role): void
    {
        $user = User::factory()->create(['role' => $role]);

        $this->actingAs($user)
            ->post('/journal/standards', ['normatives' => []])
            ->assertForbidden();
    }

    public function test_driver_cannot_create_journal_todo(): void
    {
        $driver = User::factory()->driver()->create();

        $this->actingAs($driver)
            ->post('/journal/todo', ['title' => 'Не должна создаться'])
            ->assertForbidden();

        $this->assertDatabaseCount('journal_tasks', 0);
    }

    public function test_instructor_can_create_journal_todo(): void
    {
        $instructor = User::factory()->instructor()->create();

        $this->actingAs($instructor)
            ->post('/journal/todo', ['title' => 'Задача инструктора'])
            ->assertRedirect(route('journal.index'));

        $this->assertDatabaseHas('journal_tasks', [
            'user_id' => $instructor->id,
            'title' => 'Задача инструктора',
            'status' => 'pending',
        ]);
    }

    public function test_is_instructor_helper(): void
    {
        $instructor = User::factory()->instructor()->make();
        $driver = User::factory()->driver()->make();

        $this->assertTrue($instructor->isInstructor());
        $this->assertFalse($driver->isInstructor());
    }
}
