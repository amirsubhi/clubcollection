<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\Club;
use App\Models\ClubJobLevel;
use App\Models\FeeRate;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Per-club job levels (club_job_levels): the legacy backfill, the Job levels page (add, rename, reorder,
 * deactivate, delete, audit), and every place that used to hard-code the five levels.
 */
class JobLevelTest extends TestCase
{
    private const CREATE = '2026_11_22_000000_create_club_job_levels_table.php';

    private const CHANGE = '2026_11_22_000001_change_job_level_columns_to_string.php';

    private function migration(string $file): object
    {
        return require database_path('migrations/'.$file);
    }

    private function attach(Club $club, string $level, string $role = 'member'): User
    {
        $user = User::factory()->create(['role' => $role === 'admin' ? 'admin' : 'member']);
        $club->members()->attach($user, ['role' => $role, 'job_level' => $level, 'joined_date' => now()->toDateString(), 'is_active' => true]);

        return $user;
    }

    private function level(Club $club, string $code): ClubJobLevel
    {
        return ClubJobLevel::where('club_id', $club->id)->where('code', $code)->sole();
    }

    // ── Migrations ───────────────────────────────────────────────────────

    public function test_backfill_gives_existing_clubs_the_five_legacy_levels(): void
    {
        $old = Club::factory()->create();
        ClubJobLevel::where('club_id', $old->id)->delete();
        $member = $this->attach($old, 'non_exec');
        $kept = Club::factory()->withJobLevels(['pegawai' => 'Pegawai'])->create();

        $this->migration(self::CREATE)->backfill();

        $old->refresh();
        $this->assertSame(['gm' => 'GM', 'agm' => 'AGM', 'manager' => 'Manager', 'executive' => 'Executive', 'non_exec' => 'Non-Executive'], $old->jobLevelNames());
        $this->assertSame('Non-Executive', $old->jobLevelName($old->members()->where('users.id', $member->id)->first()->pivot->job_level));
        // A club that already has levels is left alone.
        $this->assertSame(['pegawai' => 'Pegawai'], $kept->refresh()->jobLevelNames());
    }

    public function test_job_level_columns_take_club_codes_and_keep_their_index(): void
    {
        $club = Club::factory()->withJobLevels(['pegawai_kanan' => 'Pegawai Kanan'])->create();
        $user = $this->attach($club, 'pegawai_kanan');
        FeeRate::factory()->create(['club_id' => $club->id, 'job_level' => 'pegawai_kanan']);

        $this->assertDatabaseHas('club_user', ['user_id' => $user->id, 'job_level' => 'pegawai_kanan']);
        $this->assertDatabaseHas('fee_rates', ['club_id' => $club->id, 'job_level' => 'pegawai_kanan']);
        $this->assertContains('club_user_job_level_idx', array_column(Schema::getIndexes('club_user'), 'name'));
        $this->assertContains('club_job_levels_club_id_code_unique', array_column(Schema::getIndexes('club_job_levels'), 'name'));
    }

    /** Rolls the two migrations back and forward on the live schema: SQLite only (DDL is transactional there). */
    public function test_migrations_round_trip_keeps_legacy_data_and_refuse_a_lossy_rollback(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('DDL inside the test transaction: SQLite only. The backfill itself is tested above on every database.');
        }
        $club = Club::factory()->create();
        $member = $this->attach($club, 'executive');
        FeeRate::factory()->create(['club_id' => $club->id, 'job_level' => 'manager', 'monthly_amount' => 20]);

        $this->migration(self::CHANGE)->down();
        $this->migration(self::CREATE)->down();
        $this->assertFalse(Schema::hasTable('club_job_levels'));

        $this->migration(self::CREATE)->up();
        $this->migration(self::CHANGE)->up();

        $club = Club::find($club->id);
        $this->assertSame(['gm', 'agm', 'manager', 'executive', 'non_exec'], array_keys($club->jobLevelNames()));
        $this->assertSame('Executive', $club->jobLevelName($club->members()->where('users.id', $member->id)->first()->pivot->job_level));
        $this->assertDatabaseHas('fee_rates', ['club_id' => $club->id, 'job_level' => 'manager']);
        $this->assertContains('club_user_job_level_idx', array_column(Schema::getIndexes('club_user'), 'name'));

        // A club-defined code can't go back into the old enum: down() refuses instead of truncating it.
        $this->attach($club, 'pegawai');
        $this->expectException(\RuntimeException::class);
        $this->migration(self::CHANGE)->down();
    }

    // ── New clubs ────────────────────────────────────────────────────────

    public function test_a_new_club_starts_with_one_level_ahli(): void
    {
        $this->actingAsSuperAdmin();

        $this->post(route('admin.clubs.store'), ['name' => 'Kelab Baru', 'email' => 'baru@example.test'])->assertRedirect();

        $club = Club::where('name', 'Kelab Baru')->sole();
        $this->assertSame(['member' => 'Ahli'], $club->jobLevelNames());
    }

    // ── Job levels page ──────────────────────────────────────────────────

    public function test_club_admin_adds_a_level_with_a_slugged_code_and_it_is_audited(): void
    {
        $club = Club::factory()->create();
        $this->actingAsClubAdmin($club);

        $this->get(route('admin.clubs.job-levels.index', $club))->assertOk()->assertSee('Executive')->assertSee('Manager');

        $this->post(route('admin.clubs.job-levels.store', $club), ['name' => 'Pegawai Kanan'])
            ->assertRedirect(route('admin.clubs.job-levels.index', $club));

        $level = $this->level($club, 'pegawai_kanan');
        $this->assertSame('Pegawai Kanan', $level->name);
        $this->assertTrue($level->is_active);
        $this->assertSame(3, $level->sort_order, 'goes last');
        $log = AuditLog::where('action', 'job_level.created')->sole();
        $this->assertSame($club->id, $log->club_id);
        $this->assertStringContainsString('Pegawai Kanan', $log->description);
    }

    public function test_names_are_unique_per_club_and_codes_get_a_suffix(): void
    {
        $club = Club::factory()->create();
        $this->actingAsClubAdmin($club);

        // Same name in another case, or another level's code: refused.
        $this->post(route('admin.clubs.job-levels.store', $club), ['name' => 'EXECUTIVE'])->assertSessionHasErrors('name');
        $this->post(route('admin.clubs.job-levels.store', $club), ['name' => 'manager'])->assertSessionHasErrors('name');

        // Rename Manager; a new "Manager" then needs a fresh code.
        $this->put(route('admin.job-levels.update', $this->level($club, 'manager')), ['name' => 'Pengurus', 'sort_order' => 2]);
        $this->post(route('admin.clubs.job-levels.store', $club), ['name' => 'Manager!'])->assertSessionHasNoErrors();
        $this->assertSame('Manager!', $this->level($club, 'manager_2')->name);

        // Another club may use the same name.
        $other = Club::factory()->create();
        $this->actingAsSuperAdmin();
        $this->post(route('admin.clubs.job-levels.store', $other), ['name' => 'Pengurus'])->assertSessionHasNoErrors();
        $this->assertSame('Pengurus', $this->level($other, 'pengurus')->name);
    }

    public function test_rename_and_reorder_keep_the_code_and_are_audited(): void
    {
        $club = Club::factory()->create();
        $this->actingAsClubAdmin($club);
        $member = $this->attach($club, 'manager');

        $this->put(route('admin.job-levels.update', $this->level($club, 'manager')), ['name' => 'Pengurus', 'sort_order' => 0])
            ->assertRedirect(route('admin.clubs.job-levels.index', $club));

        $club->refresh();
        $this->assertSame(['manager' => 'Pengurus', 'executive' => 'Executive'], $club->jobLevelNames(), 'renamed and moved first');
        $this->assertDatabaseHas('club_user', ['user_id' => $member->id, 'job_level' => 'manager']);
        $log = AuditLog::where('action', 'job_level.updated')->sole();
        $this->assertEquals(['name' => 'Manager', 'sort_order' => 2], $log->old_values); // assertEquals: MySQL sorts JSON keys
        $this->assertEquals(['name' => 'Pengurus', 'sort_order' => 0], $log->new_values);

        $this->get(route('admin.clubs.members.index', $club))->assertSee('Pengurus');
    }

    public function test_deactivate_and_reactivate_are_audited_and_the_last_active_level_stays(): void
    {
        $club = Club::factory()->create();
        $this->actingAsClubAdmin($club);
        $manager = $this->level($club, 'manager');

        $this->patch(route('admin.job-levels.active', $manager), ['is_active' => 0])->assertRedirect();
        $this->assertFalse($manager->fresh()->is_active);
        $this->assertSame(1, AuditLog::where('action', 'job_level.deactivated')->count());

        // Executive is now the only active level.
        $this->patch(route('admin.job-levels.active', $this->level($club, 'executive')), ['is_active' => 0])->assertSessionHas('error');
        $this->assertTrue($this->level($club, 'executive')->is_active);

        $this->patch(route('admin.job-levels.active', $manager), ['is_active' => 1]);
        $this->assertTrue($manager->fresh()->is_active);
        $this->assertSame(1, AuditLog::where('action', 'job_level.reactivated')->count());
    }

    public function test_a_level_in_use_can_not_be_deleted_but_an_unused_one_can(): void
    {
        $club = Club::factory()->withJobLevels(['a' => 'A', 'b' => 'B', 'c' => 'C'])->create();
        $this->actingAsClubAdmin($club); // attached on 'manager': no such level here
        $this->attach($club, 'a');
        FeeRate::factory()->create(['club_id' => $club->id, 'job_level' => 'b']);

        foreach (['a', 'b'] as $code) {
            $this->delete(route('admin.job-levels.destroy', $this->level($club, $code)))->assertSessionHas('error');
            $this->assertDatabaseHas('club_job_levels', ['club_id' => $club->id, 'code' => $code]);
        }
        $this->get(route('admin.clubs.job-levels.index', $club))->assertDontSee('Delete job level A')->assertSee('Delete job level C');

        $this->delete(route('admin.job-levels.destroy', $this->level($club, 'c')))->assertRedirect(route('admin.clubs.job-levels.index', $club));
        $this->assertDatabaseMissing('club_job_levels', ['club_id' => $club->id, 'code' => 'c']);
        $this->assertEquals(['code' => 'c', 'name' => 'C', 'sort_order' => 3, 'is_active' => true], AuditLog::where('action', 'job_level.deleted')->sole()->old_values);
    }

    public function test_club_admin_of_one_club_can_not_manage_another_clubs_levels(): void
    {
        $a = Club::factory()->create();
        $b = Club::factory()->create();
        $this->actingAsClubAdmin($a);
        $level = $this->level($b, 'manager');

        $this->get(route('admin.clubs.job-levels.index', $b))->assertForbidden();
        $this->post(route('admin.clubs.job-levels.store', $b), ['name' => 'Hack'])->assertForbidden();
        $this->put(route('admin.job-levels.update', $level), ['name' => 'Hack', 'sort_order' => 1])->assertForbidden();
        $this->patch(route('admin.job-levels.active', $level), ['is_active' => 0])->assertForbidden();
        $this->delete(route('admin.job-levels.destroy', $level))->assertForbidden();

        $this->assertSame('Manager', $level->fresh()->name);
        $this->assertTrue($level->fresh()->is_active);
        $this->assertSame(0, AuditLog::where('action', 'like', 'job_level.%')->count());
    }

    // ── Members and fee rates use the club's levels ──────────────────────

    public function test_member_validation_takes_only_this_clubs_active_levels(): void
    {
        $club = Club::factory()->create();
        Club::factory()->withJobLevels(['pilot' => 'Pilot'])->create();
        $this->actingAsClubAdmin($club);
        $this->level($club, 'manager')->update(['is_active' => false]);
        $form = fn (string $level, string $email) => ['name' => 'Ali', 'email' => $email, 'job_level' => $level, 'joined_date' => now()->toDateString()];

        $this->get(route('admin.clubs.members.create', $club))->assertSee('value="executive"', false)->assertDontSee('value="manager"', false);
        $this->post(route('admin.clubs.members.store', $club), $form('pilot', 'p@example.test'))->assertSessionHasErrors('job_level');
        $this->post(route('admin.clubs.members.store', $club), $form('manager', 'm@example.test'))->assertSessionHasErrors('job_level');
        $this->post(route('admin.clubs.members.store', $club), $form('gm', 'g@example.test'))->assertSessionHasErrors('job_level');
        Mail::fake();
        $this->post(route('admin.clubs.members.store', $club), $form('executive', 'e@example.test'))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('club_user', ['club_id' => $club->id, 'job_level' => 'executive']);
    }

    public function test_a_member_on_a_deactivated_level_keeps_it_and_its_name(): void
    {
        $club = Club::factory()->create();
        $this->actingAsClubAdmin($club);
        $member = $this->attach($club, 'manager');
        $this->level($club, 'manager')->update(['is_active' => false, 'name' => 'Pengurus Lama']);

        $this->get(route('admin.clubs.members.index', $club))->assertSee('Pengurus Lama');
        $this->get(route('admin.members.edit', ['member' => $member, 'club' => $club->id]))->assertOk()->assertSee('Pengurus Lama (inactive)');
        $this->put(route('admin.members.update', ['member' => $member, 'club' => $club->id]), ['job_level' => 'manager', 'joined_date' => now()->toDateString(), 'is_active' => 1])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('club_user', ['user_id' => $member->id, 'job_level' => 'manager']);
    }

    public function test_fee_rates_are_set_for_a_custom_level_and_charged_to_its_members(): void
    {
        $this->withoutVite();
        $club = Club::factory()->withJobLevels(['pegawai' => 'Pegawai', 'lama' => 'Lama'])->create();
        $this->level($club, 'lama')->update(['is_active' => false]);
        $admin = $this->actingAsClubAdmin($club);

        $this->get(route('admin.clubs.fee-rates.create', $club))->assertOk()->assertSee('Pegawai')->assertDontSee('Lama');
        $this->post(route('admin.clubs.fee-rates.store', $club), [
            'rates' => [['job_level' => 'lama', 'monthly_amount' => '1.00', 'effective_from' => now()->toDateString()]],
        ])->assertSessionHasErrors('rates.0.job_level');
        $this->post(route('admin.clubs.fee-rates.store', $club), [
            'rates' => [['job_level' => 'pegawai', 'monthly_amount' => '15.00', 'effective_from' => now()->startOfYear()->toDateString()]],
        ])->assertRedirect(route('admin.clubs.fee-rates.index', $club));

        $this->assertDatabaseHas('fee_rates', ['club_id' => $club->id, 'job_level' => 'pegawai', 'effective_to' => null]);
        $this->assertStringContainsString('Pegawai: RM 15.00', AuditLog::where('action', 'fee_rate.updated')->sole()->description);
        $this->get(route('admin.clubs.fee-rates.index', $club))->assertSee('Pegawai')->assertSee('RM 15.00');

        // A member on the level pays that rate ahead.
        $member = $this->attach($club, 'pegawai');
        $this->actingAs($member)->post(route('member.payments.generate-future', $club), [
            'period_start' => now()->startOfMonth()->addMonthsNoOverflow(1)->toDateString(), 'frequency' => 'monthly',
        ]);
        $this->assertDatabaseHas('payments', ['user_id' => $member->id, 'club_id' => $club->id, 'amount' => '15.00']);
    }

    public function test_csv_import_takes_the_level_by_code_or_name_in_any_case(): void
    {
        Mail::fake();
        $club = Club::factory()->withJobLevels(['pegawai_kanan' => 'Pegawai Kanan', 'lama' => 'Lama'])->create();
        $this->level($club, 'lama')->update(['is_active' => false]);
        $this->actingAsClubAdmin($club);

        $csv = "name,email,job_level,role,joined_date\n"
            ."By Code,code@example.test,PEGAWAI_KANAN,member,2025-01-01\n"
            ."By Name,name@example.test,pegawai kanan,member,2025-01-01\n"
            ."Inactive,inactive@example.test,Lama,member,2025-01-01\n"
            ."Other,other@example.test,gm,member,2025-01-01\n";
        $this->post(route('admin.members.import.process', $club), ['file' => UploadedFile::fake()->createWithContent('m.csv', $csv)])
            ->assertSessionHas('import_imported', 2);

        foreach (['code@example.test', 'name@example.test'] as $email) {
            $this->assertSame('pegawai_kanan', $club->members()->where('email', $email)->first()->pivot->job_level);
        }
        $errors = session('import_errors');
        $this->assertCount(2, $errors);
        $this->assertStringContainsString('Pegawai Kanan (pegawai_kanan)', $errors[0]['reason']);
        $this->assertDatabaseMissing('users', ['email' => 'inactive@example.test']);

        // The page and the template list this club's levels.
        $this->get(route('admin.members.import', $club))->assertSee('Pegawai Kanan')->assertSee('pegawai_kanan')->assertDontSee('non_exec');
        $this->assertStringContainsString('Pegawai Kanan', $this->get(route('admin.members.template', $club))->streamedContent());
    }

    // ── Read-only pages ──────────────────────────────────────────────────

    public function test_statistics_merge_levels_with_the_same_name_across_clubs(): void
    {
        $a = Club::factory()->withJobLevels(['mgr' => 'Manager', 'staff' => 'Staf'])->create();
        $b = Club::factory()->withJobLevels(['manager' => 'manager'])->create();
        $this->attach($a, 'mgr');
        $this->attach($a, 'staff');
        $this->attach($b, 'manager');
        $this->attach($b, 'orphan_code');
        $this->actingAsSuperAdmin();

        $response = $this->get(route('admin.statistics'))->assertOk();
        $this->assertSame(['manager' => 2, 'orphan_code' => 1, 'staf' => 1], $response->viewData('jobLevelDistribution'));
        $this->assertSame(['manager' => 'Manager', 'orphan_code' => 'orphan_code', 'staf' => 'Staf'], $response->viewData('jobLevelLabels'));
    }

    public function test_member_home_shows_the_clubs_name_for_the_level(): void
    {
        $this->withoutVite();
        $club = Club::factory()->withJobLevels(['pegawai' => 'Pegawai Kanan'])->create(['name' => 'Kelab A']);
        $member = $this->attach($club, 'pegawai');
        $this->actingAs($member);

        $this->get(route('member.dashboard'))->assertInertia(fn (Assert $page) => $page->where('clubs.0.jobLevel', 'Pegawai Kanan'));
    }
}
