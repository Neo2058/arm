<?php

namespace Tests\Feature;

use App\Models\ArmPersonnel;
use App\Models\PhoneContact;
use App\Models\User;
use App\Services\ClickHouseService;
use App\Services\PhoneDirectory;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PhoneDirectoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        ClickHouseService::fake();
        Cache::flush();
    }

    public function test_normalization(): void
    {
        foreach (['+7 (999) 123-45-67', '8 999 123 45 67', '9991234567', '+79991234567'] as $raw) {
            $this->assertSame('+79991234567', PhoneDirectory::normalize($raw));
        }
        $this->assertSame('+74951234567', PhoneDirectory::normalize('8(495)1234567'));
        $this->assertSame('+442079460123', PhoneDirectory::normalize('+44 20 7946 0123'));
        $this->assertSame('+85212345678', PhoneDirectory::normalize('+852 1234 5678'));
        $this->assertSame('+3312345678', PhoneDirectory::normalize('+33 12345678'));
        foreach (['', '123', '+7<script>9991234567', '79991234567 доб.12', '++79991234567'] as $raw) {
            $this->assertNull(PhoneDirectory::normalize($raw));
        }
    }

    public function test_guest_and_blocked_user_cannot_read_or_create(): void
    {
        $this->getJson('/api/mobile/phones')->assertUnauthorized();
        $this->postJson('/api/mobile/phones', [])->assertUnauthorized();
        $this->getJson('/api/phones')->assertUnauthorized();
        $blocked = User::factory()->create(['is_active' => false]);
        $this->withToken($blocked->createToken('mobile')->plainTextToken);
        $this->getJson('/api/mobile/phones')->assertForbidden();
        $this->postJson('/api/mobile/phones', ['name' => 'Иванов', 'phone' => '9991234567'])->assertForbidden();
        $this->assertDatabaseCount('phone_contacts', 0);
    }

    public function test_contact_is_shared_and_cannot_be_created_under_another_name(): void
    {
        $author = User::factory()->driver()->create();
        $other = User::factory()->driver()->create();
        $this->withToken($author->createToken('mobile')->plainTextToken);
        $id = $this->postJson('/api/mobile/phones', ['name' => ' Иванов   Иван ', 'phone' => '8(999)1234567', 'created_by' => $other->id])
            ->assertCreated()->assertJsonPath('phone.name', 'Иванов Иван')->json('phone.id');
        $this->assertDatabaseHas('phone_contacts', ['id' => $id, 'phone' => '+79991234567', 'created_by' => $author->id]);
        foreach (['+7 999 123-45-67', '9991234567'] as $phone) {
            $this->postJson('/api/mobile/phones', ['name' => 'Петров', 'phone' => $phone])->assertUnprocessable()->assertJsonValidationErrors('phone');
        }
        $this->assertDatabaseCount('phone_contacts', 1);
        $events = array_values(array_filter(ClickHouseService::recorded(), fn ($row) => $row['action_type'] === 'phone.created'));
        $this->assertCount(1, $events);
        $this->assertSame($author->id, $events[0]['user_id']);
        $this->assertStringNotContainsString('Иванов', json_encode($events[0], JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString('9991234567', json_encode($events[0]));
        $this->withToken($other->createToken('mobile')->plainTextToken)->getJson('/api/mobile/phones?q=999123')
            ->assertOk()->assertJsonPath('phones.0.id', $id)->assertHeader('Cache-Control', 'no-store, private');
        $this->patchJson('/api/mobile/phones/'.$id, ['name' => 'Петров'])->assertNotFound();
        $this->deleteJson('/api/mobile/phones/'.$id)->assertNotFound();
    }

    public function test_web_and_mobile_use_the_same_directory(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->postJson('/api/phones', ['name' => 'Сидоров', 'phone' => '+79998887766'])->assertCreated();
        $this->getJson('/api/phones?q=888')->assertOk()->assertJsonCount(1, 'phones');
        $this->getJson('/api/mobile/phones?q=888')->assertOk()->assertJsonPath('phones.0.name', 'Сидоров');
    }

    public function test_database_rejects_duplicate_even_without_http_validation(): void
    {
        PhoneContact::create(['name' => 'Иванов', 'phone' => '+79991234567']);
        $this->expectException(UniqueConstraintViolationException::class);
        PhoneContact::create(['name' => 'Петров', 'phone' => '+79991234567']);
    }

    public function test_validation_and_throttle(): void
    {
        $user = User::factory()->create();
        $this->withToken($user->createToken('mobile')->plainTextToken);
        $this->postJson('/api/mobile/phones', ['name' => '123', 'phone' => '123'])->assertUnprocessable();
        for ($i = 0; $i < 9; $i++) {
            $this->postJson('/api/mobile/phones', [])->assertUnprocessable();
        }
        $this->postJson('/api/mobile/phones', [])->assertTooManyRequests();
    }

    public function test_web_routes_keep_device_and_barrier_checks(): void
    {
        $user = User::factory()->driver()->create();
        $this->actingAs($user)->getJson('/api/phones')->assertRedirect(route('device.register.form'));
        $user->devices()->create(['device_key' => 'phones-test', 'is_approved' => true]);
        $this->withHeader('X-Device-Key', 'phones-test')->getJson('/api/phones')->assertRedirect(route('barrier.show'));
        $this->withSession(['dynamic_barrier_passed' => true])->getJson('/api/phones')->assertOk();
    }

    public function test_pagination_does_not_hide_contacts(): void
    {
        for ($i = 0; $i < 101; $i++) {
            PhoneContact::create(['name' => 'Contact '.sprintf('%03d', $i), 'phone' => '+7999000'.sprintf('%04d', $i)]);
        }
        $user = User::factory()->create();
        $this->withToken($user->createToken('mobile')->plainTextToken);
        $this->getJson('/api/mobile/phones')->assertJsonCount(100, 'phones')->assertJsonPath('has_more', true);
        $this->getJson('/api/mobile/phones?page=2')->assertJsonCount(1, 'phones')->assertJsonPath('has_more', false);
    }

    public function test_personnel_import_is_explicit_idempotent_and_reports_issues(): void
    {
        $one = User::factory()->create();
        $two = User::factory()->create();
        ArmPersonnel::create(['user_id' => $one->id, 'tab_number' => '01', 'full_name' => 'Иванов', 'phone_primary' => '9991234567', 'phone_secondary' => 'bad']);
        ArmPersonnel::create(['user_id' => $two->id, 'tab_number' => '02', 'full_name' => 'Петров', 'phone_primary' => '89991234567']);
        $this->artisan('phones:import-personnel')->expectsOutput('{"new":1,"existing":0,"invalid":1,"conflicts":1}')->assertExitCode(0);
        $this->assertDatabaseCount('phone_contacts', 0);
        $this->artisan('phones:import-personnel --apply')->assertExitCode(0);
        $this->artisan('phones:import-personnel --apply')->assertExitCode(0);
        $this->assertDatabaseCount('phone_contacts', 1);
        $this->assertDatabaseHas('phone_contacts', ['name' => 'Иванов', 'phone' => '+79991234567']);
    }
}
