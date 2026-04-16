<?php

namespace Tests\Feature\Admin;

use App\Mail\MemberWelcome;
use App\Models\Club;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MemberCsvImportTest extends TestCase
{
    private function makeCsv(array $rows, bool $withHeader = true): string
    {
        $lines = [];
        if ($withHeader) {
            $lines[] = 'name,email,job_level,role,joined_date';
        }
        foreach ($rows as $row) {
            $lines[] = implode(',', $row);
        }
        return implode("\n", $lines);
    }

    private function uploadCsv(Club $club, string $content, string $filename = 'members.csv'): \Illuminate\Testing\TestResponse
    {
        $file = UploadedFile::fake()->createWithContent($filename, $content);
        return $this->post(route('admin.members.import.process', $club), ['file' => $file]);
    }

    public function test_import_page_loads_for_club_admin(): void
    {
        $club = Club::factory()->create();
        $this->actingAsClubAdmin($club);

        $this->get(route('admin.members.import', $club))->assertOk();
    }

    public function test_valid_csv_imports_all_rows(): void
    {
        Mail::fake();
        $club = Club::factory()->create();
        $this->actingAsClubAdmin($club);

        $csv = $this->makeCsv([
            ['Alice Tan', 'alice@example.com', 'manager', 'member', '2025-01-01'],
            ['Bob Lim',   'bob@example.com',   'executive', 'member', '2025-02-01'],
            ['Carla Ng',  'carla@example.com', 'gm', 'admin', '2025-03-01'],
        ]);

        $this->uploadCsv($club, $csv)
            ->assertSessionHas('import_imported', 3);

        $this->assertDatabaseHas('users', ['email' => 'alice@example.com']);
        $this->assertDatabaseHas('users', ['email' => 'bob@example.com']);
        $this->assertDatabaseHas('users', ['email' => 'carla@example.com']);
    }

    public function test_import_skips_header_row(): void
    {
        Mail::fake();
        $club = Club::factory()->create();
        $this->actingAsClubAdmin($club);

        // Header row should not be treated as a member
        $csv = $this->makeCsv([
            ['Alice Tan', 'alice2@example.com', 'manager', 'member', '2025-01-01'],
        ]);

        $this->uploadCsv($club, $csv)
            ->assertSessionHas('import_imported', 1);

        // The literal "name" header should not become a user
        $this->assertDatabaseMissing('users', ['email' => 'email']);
    }

    public function test_import_skips_row_with_fewer_than_5_columns(): void
    {
        Mail::fake();
        $club = Club::factory()->create();
        $this->actingAsClubAdmin($club);

        $csv = "name,email,job_level,role,joined_date\nfoo,bar,baz";

        $response = $this->uploadCsv($club, $csv);
        $response->assertSessionHas('import_imported', 0);

        $errors = session('import_errors');
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('fewer than 5', $errors[0]['reason']);
    }

    public function test_import_skips_row_with_invalid_email(): void
    {
        Mail::fake();
        $club = Club::factory()->create();
        $this->actingAsClubAdmin($club);

        $csv = $this->makeCsv([
            ['Alice Tan', 'notanemail', 'manager', 'member', '2025-01-01'],
        ]);

        $this->uploadCsv($club, $csv)
            ->assertSessionHas('import_imported', 0);

        $errors = session('import_errors');
        $this->assertStringContainsString('Invalid email', $errors[0]['reason']);
    }

    public function test_import_skips_row_with_invalid_job_level(): void
    {
        Mail::fake();
        $club = Club::factory()->create();
        $this->actingAsClubAdmin($club);

        $csv = $this->makeCsv([
            ['Alice Tan', 'alice3@example.com', 'ceo', 'member', '2025-01-01'],
        ]);

        $this->uploadCsv($club, $csv)
            ->assertSessionHas('import_imported', 0);

        $errors = session('import_errors');
        $this->assertStringContainsString('job_level', $errors[0]['reason']);
    }

    public function test_import_skips_row_with_invalid_role(): void
    {
        Mail::fake();
        $club = Club::factory()->create();
        $this->actingAsClubAdmin($club);

        $csv = $this->makeCsv([
            ['Alice Tan', 'alice4@example.com', 'manager', 'superuser', '2025-01-01'],
        ]);

        $this->uploadCsv($club, $csv)
            ->assertSessionHas('import_imported', 0);

        $errors = session('import_errors');
        $this->assertStringContainsString('role', $errors[0]['reason']);
    }

    public function test_import_skips_row_with_duplicate_email(): void
    {
        Mail::fake();
        $club = Club::factory()->create();
        $this->actingAsClubAdmin($club);

        $existing = User::factory()->create(['email' => 'existing@example.com']);

        $csv = $this->makeCsv([
            ['Existing User', 'existing@example.com', 'manager', 'member', '2025-01-01'],
        ]);

        $this->uploadCsv($club, $csv)
            ->assertSessionHas('import_imported', 0);

        $errors = session('import_errors');
        $this->assertStringContainsString('already exists', $errors[0]['reason']);
        $this->assertEquals(1, User::where('email', 'existing@example.com')->count());
    }

    public function test_import_skips_row_with_invalid_date(): void
    {
        Mail::fake();
        $club = Club::factory()->create();
        $this->actingAsClubAdmin($club);

        $csv = $this->makeCsv([
            ['Alice Tan', 'alice5@example.com', 'manager', 'member', 'not-a-date'],
        ]);

        $this->uploadCsv($club, $csv)
            ->assertSessionHas('import_imported', 0);

        $errors = session('import_errors');
        $this->assertStringContainsString('date', $errors[0]['reason']);
    }

    public function test_import_partial_success_reports_both_imported_and_errors(): void
    {
        Mail::fake();
        $club = Club::factory()->create();
        $this->actingAsClubAdmin($club);

        $csv = $this->makeCsv([
            ['Alice Tan', 'alice6@example.com', 'manager', 'member', '2025-01-01'],
            ['Bad Email',  'notvalid',           'manager', 'member', '2025-01-01'],
            ['Bob Lim',   'bob6@example.com',   'executive', 'member', '2025-02-01'],
        ]);

        $response = $this->uploadCsv($club, $csv);
        $response->assertSessionHas('import_imported', 2);

        $errors = session('import_errors');
        $this->assertCount(1, $errors);
    }

    public function test_import_rejects_non_csv_file(): void
    {
        $club = Club::factory()->create();
        $this->actingAsClubAdmin($club);

        $file = UploadedFile::fake()->create('members.xlsx', 10, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->post(route('admin.members.import.process', $club), ['file' => $file])
            ->assertSessionHasErrors('file');
    }

    public function test_import_rejects_missing_file(): void
    {
        $club = Club::factory()->create();
        $this->actingAsClubAdmin($club);

        $this->post(route('admin.members.import.process', $club), [])
            ->assertSessionHasErrors('file');
    }

    public function test_template_download_returns_csv(): void
    {
        $club = Club::factory()->create();
        $this->actingAsClubAdmin($club);

        $response = $this->get(route('admin.members.template', $club));
        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));

        $body = $response->streamedContent();
        $this->assertStringContainsString('name', $body);
        $this->assertStringContainsString('email', $body);
    }

    public function test_import_sends_welcome_email_for_each_imported_member(): void
    {
        Mail::fake();
        $club = Club::factory()->create();
        $this->actingAsClubAdmin($club);

        $csv = $this->makeCsv([
            ['Alice Tan', 'alice7@example.com', 'manager', 'member', '2025-01-01'],
            ['Bob Lim',   'bob7@example.com',   'executive', 'member', '2025-02-01'],
        ]);

        $this->uploadCsv($club, $csv);

        Mail::assertSent(MemberWelcome::class, 2);
    }

    public function test_import_requires_auth(): void
    {
        $club = Club::factory()->create();
        $csv  = $this->makeCsv([['Alice Tan', 'alice8@example.com', 'manager', 'member', '2025-01-01']]);
        $file = UploadedFile::fake()->createWithContent('members.csv', $csv);

        $this->post(route('admin.members.import.process', $club), ['file' => $file])
            ->assertRedirect(route('login'));
    }

    public function test_import_forbidden_for_wrong_club_admin(): void
    {
        Mail::fake();
        $clubA = Club::factory()->create();
        $clubB = Club::factory()->create();
        $this->actingAsClubAdmin($clubA);

        $csv  = $this->makeCsv([['Alice Tan', 'alice9@example.com', 'manager', 'member', '2025-01-01']]);
        $file = UploadedFile::fake()->createWithContent('members.csv', $csv);

        $this->post(route('admin.members.import.process', $clubB), ['file' => $file])
            ->assertForbidden();
    }

    public function test_import_caps_at_row_limit(): void
    {
        Mail::fake();
        $club = Club::factory()->create();
        $this->actingAsClubAdmin($club);

        // 1001 data rows — controller's limit is 1000.
        $rows = [];
        for ($i = 1; $i <= 1001; $i++) {
            $rows[] = ["User {$i}", "user{$i}@example.com", 'executive', 'member', '2025-01-01'];
        }
        $csv = $this->makeCsv($rows);

        $this->uploadCsv($club, $csv)
            ->assertSessionHas('import_imported', 1000);

        $errors = session('import_errors');
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('1000-row import limit', $errors[count($errors) - 1]['reason']);
    }

    public function test_import_strips_formula_prefix_from_name_and_email(): void
    {
        Mail::fake();
        $club = Club::factory()->create();
        $this->actingAsClubAdmin($club);

        // A hostile uploader plants formula characters at the start of the
        // name. The email cannot start with `=` and still validate, but we
        // strip it as defence in depth before the FILTER_VALIDATE_EMAIL check.
        $csv = $this->makeCsv([
            ['=cmd|"/c calc"!A1 Alice', 'safe1@example.com', 'manager', 'member', '2025-01-01'],
            ['+SUM(A1:A2) Bob',         'safe2@example.com', 'manager', 'member', '2025-01-01'],
            ['-Charlie',                'safe3@example.com', 'manager', 'member', '2025-01-01'],
            ['@Dan',                    'safe4@example.com', 'manager', 'member', '2025-01-01'],
        ]);

        $this->uploadCsv($club, $csv)
            ->assertSessionHas('import_imported', 4);

        $names = User::whereIn('email', ['safe1@example.com','safe2@example.com','safe3@example.com','safe4@example.com'])
            ->pluck('name')->all();
        foreach ($names as $name) {
            $this->assertDoesNotMatchRegularExpression('/^[=+@\-]/', $name);
        }
    }
}
