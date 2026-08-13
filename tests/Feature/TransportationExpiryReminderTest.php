<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TransportationExpiryReminderTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $mysqlConnection;

    /** @var array<string, mixed> */
    private array $schoolConnection;

    /** @var array<int, string> */
    private array $databaseFiles = [];

    private string $centralDatabase;
    private string $tenantA;
    private string $tenantB;
    private string $missingTableTenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mysqlConnection = config('database.connections.mysql');
        $this->schoolConnection = config('database.connections.school');
        $this->centralDatabase = $this->temporaryDatabase();
        $this->tenantA = $this->temporaryDatabase();
        $this->tenantB = $this->temporaryDatabase();
        $this->missingTableTenant = $this->temporaryDatabase();

        $sqlite = static fn (string $database): array => [
            'driver' => 'sqlite',
            'database' => $database,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ];

        Config::set('database.connections.mysql', $sqlite($this->centralDatabase));
        Config::set('database.connections.school', $sqlite($this->tenantA));
        DB::purge('mysql');
        DB::purge('school');
        DB::setDefaultConnection('mysql');

        Schema::connection('mysql')->create('schools', function ($table): void {
            $table->increments('id');
            $table->string('database_name')->nullable();
            $table->boolean('status')->default(true);
            $table->timestamp('deleted_at')->nullable();
        });

        foreach ([$this->tenantA, $this->tenantB] as $database) {
            Config::set('database.connections.school.database', $database);
            DB::purge('school');
            Schema::connection('school')->create('transportation_payments', function ($table): void {
                $table->increments('id');
                $table->unsignedInteger('user_id');
                $table->string('status');
                $table->date('expiry_date');
            });
        }

        Config::set('database.connections.school.database', $this->tenantA);
        DB::purge('school');
        DB::setDefaultConnection('mysql');
    }

    protected function tearDown(): void
    {
        DB::purge('school');
        DB::purge('mysql');
        Config::set('database.connections.mysql', $this->mysqlConnection);
        Config::set('database.connections.school', $this->schoolConnection);
        DB::setDefaultConnection('mysql');

        foreach ($this->databaseFiles as $databaseFile) {
            @unlink($databaseFile);
        }

        parent::tearDown();
    }

    public function test_two_active_tenants_are_isolated_and_dry_run_does_not_write(): void
    {
        $targetDate = now()->addDays(7)->toDateString();
        $this->insertSchool(1, $this->tenantA, true);
        $this->insertSchool(2, $this->tenantB, true);

        $this->insertPayment($this->tenantA, 101, $targetDate, 'paid');
        $this->insertPayment($this->tenantB, 201, $targetDate, 'paid');
        $this->insertPayment($this->tenantB, 202, now()->addDays(8)->toDateString(), 'paid');

        $this->prepareCommandConnection();
        $exitCode = Artisan::call('transport:expiry-reminder', ['--dry-run' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('School 1: 1 expiring plan(s).', $output);
        $this->assertStringContainsString('School 2: 1 expiring plan(s).', $output);
        $this->assertStringContainsString('2 plan(s) matched.', $output);
        $this->assertSame('mysql', DB::getDefaultConnection());
        $this->assertSame($this->tenantA, config('database.connections.school.database'));
        $this->assertSame(1, $this->paymentCount($this->tenantA));
        $this->assertSame(2, $this->paymentCount($this->tenantB));
    }

    public function test_inactive_and_schema_incomplete_tenants_are_skipped_safely(): void
    {
        $targetDate = now()->addDays(7)->toDateString();
        $this->insertSchool(1, $this->tenantA, true);
        $this->insertSchool(2, $this->missingTableTenant, true);
        $this->insertSchool(3, $this->tenantB, false);
        $this->insertPayment($this->tenantA, 101, $targetDate, 'paid');
        $this->insertPayment($this->tenantB, 201, $targetDate, 'paid');

        $this->prepareCommandConnection();
        $exitCode = Artisan::call('transport:expiry-reminder', ['--dry-run' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('School 2: transportation_payments table is not installed; skipped.', $output);
        $this->assertStringNotContainsString('School 3:', $output);
        $this->assertStringContainsString('1 school(s); 1 plan(s) matched.', $output);
        $this->assertSame('mysql', DB::getDefaultConnection());
        $this->assertSame($this->tenantA, config('database.connections.school.database'));
    }

    public function test_a_failed_tenant_does_not_stop_a_later_tenant_and_connection_is_restored(): void
    {
        $targetDate = now()->addDays(7)->toDateString();
        $this->insertSchool(1, $this->tenantA, true);
        $this->insertSchool(2, sys_get_temp_dir(), true);
        $this->insertSchool(3, $this->tenantB, true);
        $this->insertPayment($this->tenantA, 101, $targetDate, 'paid');
        $this->insertPayment($this->tenantB, 201, $targetDate, 'paid');

        $this->prepareCommandConnection();
        $exitCode = Artisan::call('transport:expiry-reminder', ['--dry-run' => true]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('School 3: 1 expiring plan(s).', $output);
        $this->assertStringContainsString('2 school(s); 2 plan(s) matched.', $output);
        $this->assertSame('mysql', DB::getDefaultConnection());
        $this->assertSame($this->centralDatabase, DB::connection('mysql')->getDatabaseName());
        $this->assertSame($this->tenantA, config('database.connections.school.database'));
    }

    public function test_repeated_dry_runs_are_non_mutating_and_repeatable(): void
    {
        $targetDate = now()->addDays(7)->toDateString();
        $this->insertSchool(1, $this->tenantA, true);
        $this->insertPayment($this->tenantA, 101, $targetDate, 'paid');

        $this->prepareCommandConnection();
        $this->assertSame(0, Artisan::call('transport:expiry-reminder', ['--dry-run' => true]));
        $firstOutput = Artisan::output();
        $this->assertSame(0, Artisan::call('transport:expiry-reminder', ['--dry-run' => true]));

        $this->assertSame($firstOutput, Artisan::output());
        $this->assertSame('mysql', DB::getDefaultConnection());
        $this->assertSame($this->tenantA, config('database.connections.school.database'));
        $this->assertSame(1, $this->paymentCount($this->tenantA));
    }

    private function temporaryDatabase(): string
    {
        $database = tempnam(sys_get_temp_dir(), 'eschool_transport_');
        $this->databaseFiles[] = $database;

        return $database;
    }

    private function insertSchool(int $id, string $database, bool $active): void
    {
        DB::connection('mysql')->table('schools')->insert([
            'id' => $id,
            'database_name' => $database,
            'status' => $active,
        ]);
    }

    private function insertPayment(string $database, int $userId, string $expiryDate, string $status): void
    {
        Config::set('database.connections.school.database', $database);
        DB::purge('school');
        DB::connection('school')->table('transportation_payments')->insert([
            'user_id' => $userId,
            'status' => $status,
            'expiry_date' => $expiryDate,
        ]);
    }

    private function paymentCount(string $database): int
    {
        Config::set('database.connections.school.database', $database);
        DB::purge('school');

        return DB::connection('school')->table('transportation_payments')->count();
    }

    private function prepareCommandConnection(): void
    {
        Config::set('database.connections.school.database', $this->tenantA);
        DB::purge('school');
        DB::setDefaultConnection('mysql');
    }
}
