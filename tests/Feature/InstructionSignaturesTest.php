<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckDeviceBinding;
use App\Http\Middleware\CheckDynamicBarrier;
use App\Models\Document;
use App\Models\InstructionCategory;
use App\Models\User;
use App\Models\UserInstructionSignature;
use App\Services\ClickHouseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class InstructionSignaturesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('s3');
        ClickHouseService::fake();
    }

    private function document(?array $roles = null): Document
    {
        $category = InstructionCategory::create(['name' => 'Инструктаж', 'slug' => 'briefing', 'is_active' => true, 'sort_order' => 1]);
        Storage::disk('s3')->put('instructions/test.pdf', '%PDF-1.4');

        return Document::create(['title' => 'Правила', 'file_path' => 'instructions/test.pdf', 'instruction_category_id' => $category->id, 'allowed_roles' => $roles]);
    }

    private function api(User $user)
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($user->createToken('mobile')->plainTextToken);
    }

    public function test_guest_and_forbidden_documents_are_not_exposed(): void
    {
        $doc = $this->document(['instructor']);
        $driver = User::factory()->create(['role' => 'driver']);
        $this->getJson('/api/mobile/instructions')->assertUnauthorized();
        $this->api($driver)->getJson('/api/mobile/instructions')->assertOk()->assertJsonCount(0, 'categories');
        $this->postJson('/api/mobile/instructions/'.$doc->id.'/sign', ['confirmed' => true])->assertForbidden();
        $this->assertDatabaseCount('user_instruction_signatures', 0);
    }

    public function test_super_admin_can_list_and_sign_once_without_profile_and_no_other_user_override(): void
    {
        $doc = $this->document(['instructor']);
        $super = User::factory()->create(['role' => 'super_admin']);
        $other = User::factory()->create(['role' => 'instructor']);
        $this->api($super)->getJson('/api/mobile/instructions')->assertOk()->assertJsonPath('categories.0.documents.0.signed', false)->assertDontSee('s3.amazonaws');
        $first = $this->postJson('/api/mobile/instructions/'.$doc->id.'/sign', ['confirmed' => true, 'user_id' => $other->id])->assertOk()->assertJsonPath('signed', true)->json();
        $this->travel(2)->hours();
        $this->postJson('/api/mobile/instructions/'.$doc->id.'/sign', ['confirmed' => true])->assertOk()->assertExactJson($first);
        $this->assertDatabaseCount('user_instruction_signatures', 1);
        $this->assertDatabaseHas('user_instruction_signatures', ['user_id' => $super->id]);
        $this->assertCount(1, ClickHouseService::recorded());
        $this->getJson('/api/mobile/instructions')->assertJsonPath('categories.0.documents.0.signed', true);
        $this->api($other)->getJson('/api/mobile/instructions')->assertJsonPath('categories.0.documents.0.signed', false);
    }

    public function test_confirmation_active_category_and_available_file_are_required(): void
    {
        $doc = $this->document();
        $this->api(User::factory()->create())->postJson('/api/mobile/instructions/'.$doc->id.'/sign', [])->assertUnprocessable();
        $this->postJson('/api/mobile/instructions/'.$doc->id.'/sign', ['confirmed' => false])->assertUnprocessable();
        Storage::disk('s3')->delete($doc->file_path);
        $this->postJson('/api/mobile/instructions/'.$doc->id.'/sign', ['confirmed' => true])->assertNotFound();
        $doc->instructionCategory->update(['is_active' => false]);
        $this->getJson('/api/mobile/instructions')->assertJsonCount(0, 'categories');
        $this->postJson('/api/mobile/instructions/'.$doc->id.'/sign', ['confirmed' => true])->assertNotFound();
        $this->assertDatabaseCount('user_instruction_signatures', 0);
    }

    public function test_web_and_mobile_share_records_and_web_requires_confirmation(): void
    {
        $this->withoutMiddleware([CheckDeviceBinding::class, CheckDynamicBarrier::class]);
        $doc = $this->document();
        $user = User::factory()->create(['role' => 'driver']);
        $this->actingAs($user, 'web')->get('/rosisi')->assertOk()->assertSee('name="confirmed"', false);
        $this->post('/rosisi/sign/'.$doc->id, [])->assertSessionHasErrors('confirmed');
        $this->post('/rosisi/sign/'.$doc->id, ['confirmed' => 1])->assertRedirect();
        $date = UserInstructionSignature::firstOrFail()->signed_at;
        $this->api($user)->getJson('/api/mobile/instructions')->assertOk()->assertJsonPath('categories.0.documents.0.signed', true);
        $this->postJson('/api/mobile/instructions/'.$doc->id.'/sign', ['confirmed' => true])->assertOk();
        $this->assertTrue($date->equalTo(UserInstructionSignature::firstOrFail()->signed_at));
    }

    public function test_inactive_and_monitor_token_are_blocked_and_unassigned_document_cannot_be_signed(): void
    {
        $doc = $this->document();
        $user = User::factory()->create(['role' => 'super_admin']);
        $this->app['auth']->forgetGuards();
        $this->withToken($user->createToken('monitor', ['monitor:read'])->plainTextToken)->getJson('/api/mobile/instructions')->assertForbidden();
        $user->update(['is_active' => false]);
        $this->api($user)->getJson('/api/mobile/instructions')->assertForbidden();
        $user->update(['is_active' => true]);
        $doc->update(['instruction_category_id' => null]);
        $this->api($user)->postJson('/api/mobile/instructions/'.$doc->id.'/sign', ['confirmed' => true])->assertNotFound();
    }
}
