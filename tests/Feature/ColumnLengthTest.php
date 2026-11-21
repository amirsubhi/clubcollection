<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Club;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Payment;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Values as long as the app allows must fit their columns, and CSV input must
 * be what MySQL / MariaDB accept. SQLite ignores VARCHAR lengths and stores
 * any bytes, so the column cases only fail when the suite runs on MySQL /
 * MariaDB (the production databases); the CSV checks are app-side.
 */
class ColumnLengthTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_long_audit_description_is_kept_whole(): void
    {
        $text = str_repeat('Refund RM 30.00 to the member. ', 30);
        AuditService::log('payment.gateway_paid_during_verification', $text);

        $this->assertSame($text, AuditLog::latest('id')->value('description'));
    }

    public function test_fund_usage_description_of_500_characters_is_saved(): void
    {
        $club = Club::factory()->create();
        $category = ExpenseCategory::factory()->for($club)->create();
        $this->actingAsClubAdmin($club);
        $description = str_repeat('a', 500);

        $this->post(route('admin.clubs.fund-usage.store', $club), [
            'intent' => 'draft',
            'type' => 'expense',
            'expense_category_id' => $category->id,
            'description' => $description,
            'payee' => 'Kedai Runcit',
            'amount' => '10.00',
            'expense_date' => now()->toDateString(),
            'paid_date' => now()->toDateString(),
        ])->assertSessionHasNoErrors();

        $this->assertSame($description, Expense::latest('id')->value('description'));
    }

    public function test_mark_paid_by_transfer_keeps_a_255_character_reference(): void
    {
        $club = Club::factory()->create();
        $member = User::factory()->create();
        $fee = Payment::factory()->forClub($club)->forMember($member)->pending()->create();
        $this->actingAsClubAdmin($club);
        $reference = str_repeat('R', 255);

        $this->patch(route('admin.payments.mark-paid', $fee), $this->recordPayment(['method' => 'transfer', 'reference' => $reference]))
            ->assertSessionHas('success');

        $this->assertSame($reference, $fee->fresh()->transfer_bank_ref);
    }

    public function test_csv_import_refuses_a_name_longer_than_255_characters(): void
    {
        Mail::fake();
        $club = Club::factory()->create();
        $this->actingAsClubAdmin($club);
        $csv = "name,email,job_level,role,joined_date\n".str_repeat('N', 256).",long@example.com,manager,member,2025-01-01\nShort Name,short@example.com,manager,member,2025-01-01";

        $this->post(route('admin.members.import.process', $club), ['file' => UploadedFile::fake()->createWithContent('m.csv', $csv)])
            ->assertSessionHas('import_imported', 1);

        $this->assertStringContainsString('longer than 255', session('import_errors')[0]['reason']);
        $this->assertDatabaseMissing('users', ['email' => 'long@example.com']);
    }

    public function test_csv_import_refuses_an_email_too_long_for_its_column(): void
    {
        Mail::fake();
        $club = Club::factory()->create();
        $this->actingAsClubAdmin($club);
        $long = str_repeat('a', 60).'@'.str_repeat('b', 63).'.'.str_repeat('c', 63).'.'.str_repeat('d', 63).'.com';
        $this->assertGreaterThan(255, strlen($long));

        $this->importCsv($club, "name,email,job_level,role,joined_date\nLong Email,{$long},manager,member,2025-01-01")
            ->assertSessionHas('import_imported', 0);
        // PHP's email check refuses 255+ characters, before the column could.
        $this->assertSame('Invalid email address.', session('import_errors')[0]['reason']);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_csv_import_treats_emails_without_case(): void
    {
        Mail::fake();
        $club = Club::factory()->create();
        User::factory()->create(['email' => 'ali@club.my']);
        $this->actingAsClubAdmin($club);

        $this->importCsv($club, "name,email,job_level,role,joined_date\nAli,Ali@Club.my,manager,member,2025-01-01\nBob,bob@club.my,manager,member,2025-01-01\nBob Again,BOB@club.my,manager,member,2025-01-01")
            ->assertSessionHas('import_imported', 1);

        $this->assertSame(1, User::whereRaw('lower(email) = ?', ['bob@club.my'])->count());
        $this->assertCount(2, session('import_errors'));
    }

    public function test_csv_import_converts_a_windows_1252_file_to_utf8(): void
    {
        Mail::fake();
        $club = Club::factory()->create();
        $this->actingAsClubAdmin($club);
        // "José Nurul ‘Ain" as Excel on Windows saves it.
        $name = "Jos\xE9 Nurul \x91Ain";

        $this->importCsv($club, "name,email,job_level,role,joined_date\n{$name},jose@club.my,manager,member,2025-01-01")
            ->assertSessionHas('import_imported', 1);

        $this->assertSame('José Nurul ‘Ain', User::where('email', 'jose@club.my')->value('name'));
    }

    private function importCsv(Club $club, string $csv): \Illuminate\Testing\TestResponse
    {
        return $this->post(route('admin.members.import.process', $club), ['file' => UploadedFile::fake()->createWithContent('m.csv', $csv)]);
    }
}
