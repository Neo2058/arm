<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Http\Middleware\CheckDeviceBinding;
use App\Http\Middleware\CheckDynamicBarrier;
use App\Models\ArmAbsence;
use App\Models\ArmAppointment;
use App\Models\ArmPersonnel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Tests\Support\DbfWriter;
use Tests\TestCase;

class ArmPersonnelImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->withoutMiddleware([
            CheckDeviceBinding::class,
            CheckDynamicBarrier::class,
        ]);
    }

    public function test_import_lkm_nazn_otvm(): void
    {
        $dir = sys_get_temp_dir().'/arm_lkm_'.uniqid();
        mkdir($dir);

        DbfWriter::write($dir.'/LKM.DBF', [
            ['name' => 'TABNOM', 'type' => 'C', 'length' => 4],
            ['name' => 'FIO', 'type' => 'C', 'length' => 18],
            ['name' => 'DTUSTR', 'type' => 'D', 'length' => 8],
            ['name' => 'DOLZN', 'type' => 'C', 'length' => 5],
            ['name' => 'KLASS', 'type' => 'C', 'length' => 2],
            ['name' => 'DTUV', 'type' => 'D', 'length' => 8],
            ['name' => 'TEL1', 'type' => 'C', 'length' => 9],
            ['name' => 'NBRIG', 'type' => 'C', 'length' => 2],
            ['name' => 'PBR', 'type' => 'C', 'length' => 1],
            ['name' => 'RANVR1', 'type' => 'C', 'length' => 5],
            ['name' => 'PRAN1', 'type' => 'C', 'length' => 8],
        ], [
            [
                'TABNOM' => '0313', 'FIO' => 'НАДЕЖДИН Е.Е+', 'DTUSTR' => '20150804',
                'DOLZN' => 'МШ', 'KLASS' => '1', 'DTUV' => '00000000',
                'TEL1' => '250-05-68', 'NBRIG' => '46', 'PBR' => '+',
                'RANVR1' => '07.15', 'PRAN1' => 'ДП',
            ],
            [
                'TABNOM' => '0999', 'FIO' => 'УВОЛЕННЫЙ', 'DTUSTR' => '20100101',
                'DOLZN' => 'П/М', 'KLASS' => '0', 'DTUV' => '20200115',
                'TEL1' => '', 'NBRIG' => '10', 'PBR' => '',
                'RANVR1' => '', 'PRAN1' => '',
            ],
        ]);

        DbfWriter::write($dir.'/NAZN.DBF', [
            ['name' => 'TABNOM', 'type' => 'C', 'length' => 4],
            ['name' => 'DTNAZN', 'type' => 'D', 'length' => 8],
            ['name' => 'DOLZN', 'type' => 'C', 'length' => 5],
            ['name' => 'KLASS', 'type' => 'C', 'length' => 2],
            ['name' => 'UDAL', 'type' => 'C', 'length' => 1],
        ], [
            ['TABNOM' => '0313', 'DTNAZN' => '20150804', 'DOLZN' => 'МШ', 'KLASS' => '1', 'UDAL' => ''],
            ['TABNOM' => '0313', 'DTNAZN' => '20100101', 'DOLZN' => 'П/М', 'KLASS' => '0', 'UDAL' => '*'],
        ]);

        DbfWriter::write($dir.'/OTVM.DBF', [
            ['name' => 'TABNOM', 'type' => 'C', 'length' => 4],
            ['name' => 'DTN', 'type' => 'D', 'length' => 8],
            ['name' => 'DTK', 'type' => 'D', 'length' => 8],
            ['name' => 'VIDOTV', 'type' => 'C', 'length' => 3],
            ['name' => 'UDAL', 'type' => 'C', 'length' => 1],
        ], [
            ['TABNOM' => '0313', 'DTN' => '20230301', 'DTK' => '20230321', 'VIDOTV' => 'ОТ', 'UDAL' => ''],
        ]);

        $this->assertSame(0, Artisan::call('arm:import-dbf', [
            'path' => $dir,
            '--only' => 'personnel,appointments,absences',
        ]));

        $user = User::query()->where('email', 'tab0313@arm.local')->first();
        $this->assertNotNull($user);
        $this->assertSame('НАДЕЖДИН Е.Е', $user->name);
        $this->assertTrue($user->isDriver());
        $this->assertTrue($user->is_active);
        $this->assertTrue($user->profile->is_brigadir);
        $this->assertSame('0313', $user->profile->tab_number);
        $this->assertSame('1', $user->profile->normative_class);

        $card = ArmPersonnel::query()->where('tab_number', '0313')->first();
        $this->assertNotNull($card);
        $this->assertSame($user->id, $card->user_id);
        $this->assertTrue($card->is_brigadier);
        $this->assertSame('46', $card->brigade_code);
        $this->assertSame([['time' => '07.15', 'reason' => 'ДП']], $card->early_windows);

        $fired = User::query()->where('email', 'tab0999@arm.local')->first();
        $this->assertFalse((bool) $fired->is_active);
        $this->assertTrue($fired->profile->is_pomoshnik);

        $this->assertSame(1, ArmAppointment::count());
        $this->assertTrue(ArmAppointment::query()->where('tab_number', '0313')->where('position_code', 'МШ')->exists());
        $this->assertTrue(ArmAbsence::query()->where('kind_code', 'ОТ')->exists());
    }

    public function test_reimport_does_not_overwrite_password(): void
    {
        $dir = sys_get_temp_dir().'/arm_lkm2_'.uniqid();
        mkdir($dir);
        DbfWriter::write($dir.'/LKM.DBF', [
            ['name' => 'TABNOM', 'type' => 'C', 'length' => 4],
            ['name' => 'FIO', 'type' => 'C', 'length' => 18],
        ], [
            ['TABNOM' => '1111', 'FIO' => 'ИВАНОВ А.А'],
        ]);

        Artisan::call('arm:import-dbf', ['path' => $dir, '--only' => 'personnel']);
        $user = User::query()->where('email', 'tab1111@arm.local')->first();
        $user->password = 'secret-pass';
        $user->save();

        Artisan::call('arm:import-dbf', ['path' => $dir, '--only' => 'personnel']);
        $user->refresh();
        $this->assertTrue(Hash::check('secret-pass', $user->password));
        $this->assertSame(1, ArmPersonnel::query()->where('tab_number', '1111')->count());
    }

    public function test_dispatcher_can_manage_appointments_and_absences(): void
    {
        $dispatcher = User::factory()->dispatcher()->create();
        $driver = User::factory()->driver()->create();
        ArmPersonnel::create([
            'user_id' => $driver->id,
            'tab_number' => '2222',
            'full_name' => 'ТЕСТ',
        ]);

        $this->actingAs($dispatcher)->get('/naryad/partial/personnel')->assertOk();
        $this->actingAs($dispatcher)->get('/naryad/partial/appointments')->assertOk();
        $this->actingAs($dispatcher)->get('/naryad/partial/absences')->assertOk();

        $this->actingAs($dispatcher)->postJson('/naryad/appointments', [
            'tab_number' => '2222',
            'appointed_on' => '2026-01-10',
            'position_code' => 'МШ',
            'class_code' => '2',
        ])->assertOk();

        $this->actingAs($dispatcher)->postJson('/naryad/absences', [
            'tab_number' => '2222',
            'starts_on' => '2026-02-01',
            'ends_on' => '2026-02-14',
            'kind_code' => 'Б',
        ])->assertOk();

        $this->assertSame($driver->id, ArmAppointment::first()->user_id);
        $this->assertSame($driver->id, ArmAbsence::first()->user_id);
    }

    public function test_driver_cannot_open_personnel_partials(): void
    {
        $driver = User::factory()->driver()->create();
        $this->actingAs($driver)->get('/naryad/partial/personnel')->assertForbidden();
        $this->actingAs($driver)->get('/naryad/partial/appointments')->assertForbidden();
        $this->actingAs($driver)->get('/naryad/partial/absences')->assertForbidden();
    }

    public function test_does_not_demote_dispatcher_with_same_tab(): void
    {
        $dispatcher = User::factory()->dispatcher()->create([
            'email' => 'tab5555@arm.local',
        ]);
        $dispatcher->profile()->create(['tab_number' => '5555']);

        $dir = sys_get_temp_dir().'/arm_lkm3_'.uniqid();
        mkdir($dir);
        DbfWriter::write($dir.'/LKM.DBF', [
            ['name' => 'TABNOM', 'type' => 'C', 'length' => 4],
            ['name' => 'FIO', 'type' => 'C', 'length' => 18],
        ], [
            ['TABNOM' => '5555', 'FIO' => 'НАРЯДЧИК'],
        ]);

        Artisan::call('arm:import-dbf', ['path' => $dir, '--only' => 'personnel']);
        $dispatcher->refresh();
        $this->assertSame(UserRole::DISPATCHER, $dispatcher->role);
    }
}
