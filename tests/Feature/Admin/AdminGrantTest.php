<?php

namespace Tests\Feature\Admin;

use App\Mail\ClubAdminNotice;
use App\Models\AuditLog;
use App\Models\Club;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * S1: only the super admin grants the club-admin role. Club admins add and
 * edit members only and can't change or remove a club-admin row. Every grant
 * is audited and emailed to the club's admins.
 */
class AdminGrantTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function attach(Club $club, string $role = 'member'): User
    {
        $user = User::factory()->create(['role' => $role === 'admin' ? 'admin' : 'member']);
        $club->members()->attach($user, ['role' => $role, 'job_level' => 'executive', 'joined_date' => '2025-01-01', 'is_active' => true]);

        return $user;
    }

    private function newMember(array $overrides = []): array
    {
        return $overrides + ['name' => 'New Person', 'email' => 'new.person@example.test', 'job_level' => 'executive', 'joined_date' => '2025-01-15'];
    }

    public function test_club_admin_cannot_create_an_admin(): void
    {
        $club = Club::factory()->create();
        $this->actingAsClubAdmin($club);

        $this->post(route('admin.clubs.members.store', $club), $this->newMember(['role' => 'admin']))
            ->assertSessionHasErrors('role');

        $this->assertDatabaseMissing('users', ['email' => 'new.person@example.test']);
    }

    public function test_club_admin_adds_a_member_without_a_role_field(): void
    {
        $club = Club::factory()->create();
        $this->actingAsClubAdmin($club);

        $this->post(route('admin.clubs.members.store', $club), $this->newMember())->assertRedirect();

        $user = User::where('email', 'new.person@example.test')->sole();
        $this->assertSame('member', $club->members()->where('users.id', $user->id)->first()->pivot->role);
    }

    public function test_club_admin_cannot_promote_a_member(): void
    {
        $club = Club::factory()->create();
        $this->actingAsClubAdmin($club);
        $member = $this->attach($club);

        $this->patch(route('admin.members.update', $member), ['role' => 'admin', 'job_level' => 'manager', 'joined_date' => '2025-01-01', 'is_active' => true])
            ->assertSessionHasErrors('role');

        $this->assertSame('member', $club->members()->where('users.id', $member->id)->first()->pivot->role);
    }

    public function test_club_admin_can_still_edit_a_member_without_sending_a_role(): void
    {
        $club = Club::factory()->create();
        $this->actingAsClubAdmin($club);
        $member = $this->attach($club);

        $this->patch(route('admin.members.update', $member), ['job_level' => 'manager', 'joined_date' => '2025-01-01', 'is_active' => true])
            ->assertRedirect();

        $pivot = $club->members()->where('users.id', $member->id)->first()->pivot;
        $this->assertSame('member', $pivot->role);
        $this->assertSame('manager', $pivot->job_level);
    }

    public function test_club_admin_cannot_change_or_remove_another_admin(): void
    {
        $club = Club::factory()->create();
        $this->actingAsClubAdmin($club);
        $other = $this->attach($club, 'admin');

        $this->get(route('admin.members.edit', $other))->assertForbidden();
        $this->patch(route('admin.members.update', $other), ['role' => 'member', 'job_level' => 'manager', 'joined_date' => '2025-01-01', 'is_active' => true])
            ->assertForbidden();
        $this->delete(route('admin.members.destroy', $other))->assertForbidden();

        $this->assertSame('admin', $club->members()->where('users.id', $other->id)->first()->pivot->role);
    }

    public function test_club_admin_csv_import_refuses_admin_rows_with_a_message(): void
    {
        $club = Club::factory()->create();
        $this->actingAsClubAdmin($club);
        $csv = "name,email,job_level,role,joined_date\n"
            ."Ali,ali@example.test,manager,member,2025-01-01\n"
            ."Bee,bee@example.test,manager,admin,2025-01-01\n";

        $response = $this->post(route('admin.members.import.process', $club), [
            'file' => UploadedFile::fake()->createWithContent('members.csv', $csv),
        ])->assertSessionHas('import_imported', 1);

        $errors = $response->baseResponse->getSession()->get('import_errors');
        $this->assertCount(1, $errors);
        $this->assertSame('bee@example.test', $errors[0]['email']);
        $this->assertStringContainsString('Only the super admin', $errors[0]['reason']);
        $this->assertDatabaseMissing('users', ['email' => 'bee@example.test']);
    }

    public function test_super_admin_grant_is_audited_and_emailed_to_the_club_admins(): void
    {
        $club = Club::factory()->create();
        $existingAdmin = $this->attach($club, 'admin');
        $member = $this->attach($club);
        $this->actingAsSuperAdmin();

        $this->patch(route('admin.members.update', $member), ['role' => 'admin', 'job_level' => 'manager', 'joined_date' => '2025-01-01', 'is_active' => true])
            ->assertRedirect();

        $this->assertSame('admin', $club->members()->where('users.id', $member->id)->first()->pivot->role);
        $this->assertDatabaseHas('audit_logs', ['action' => 'member.admin_granted', 'auditable_id' => $member->id, 'club_id' => $club->id]);
        Mail::assertSent(ClubAdminNotice::class, fn ($m) => $m->hasTo($existingAdmin->email));
        Mail::assertSent(ClubAdminNotice::class, fn ($m) => $m->hasTo($member->email));
    }

    public function test_super_admin_csv_import_of_an_admin_is_audited(): void
    {
        $club = Club::factory()->create();
        $this->actingAsSuperAdmin();
        $csv = "name,email,job_level,role,joined_date\nBee,bee@example.test,manager,admin,2025-01-01\n";

        $this->post(route('admin.members.import.process', $club), [
            'file' => UploadedFile::fake()->createWithContent('members.csv', $csv),
        ])->assertSessionHas('import_imported', 1);

        $this->assertSame(1, AuditLog::where('action', 'member.admin_granted')->count());
        Mail::assertSent(ClubAdminNotice::class, fn ($m) => $m->hasTo('bee@example.test'));
    }

    public function test_role_select_is_hidden_from_club_admins(): void
    {
        $club = Club::factory()->create();
        $this->actingAsClubAdmin($club);

        $this->get(route('admin.clubs.members.create', $club))
            ->assertOk()
            ->assertDontSee('name="role"', false)
            ->assertSee('Only the super admin can make someone a club admin');
    }

    public function test_role_select_is_shown_to_the_super_admin(): void
    {
        $club = Club::factory()->create();
        $this->actingAsSuperAdmin();

        $this->get(route('admin.clubs.members.create', $club))->assertOk()->assertSee('name="role"', false);
    }
}
