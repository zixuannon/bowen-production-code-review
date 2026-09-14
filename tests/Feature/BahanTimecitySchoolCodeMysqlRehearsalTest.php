<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Opt-in disposable local MySQL rehearsal for the exact central mapping. */
final class BahanTimecitySchoolCodeMysqlRehearsalTest extends TestCase
{
    private string $database;
    private array $originalConnection;
    private bool $created = false;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('CENTRALIZATION_MYSQL_REHEARSAL') !== '1') {
            $this->markTestSkipped('Set CENTRALIZATION_MYSQL_REHEARSAL=1 for disposable local MySQL rehearsal.');
        }

        $host = (string) config('database.connections.mysql.host');
        if (!in_array($host, ['127.0.0.1', 'localhost'], true)) {
            $this->fail("Disposable rehearsal refuses non-local MySQL host: {$host}");
        }

        $this->database = 'eschool_centralization_'.getmypid().'_'.bin2hex(random_bytes(4));
        if (!preg_match('/\Aeschool_centralization_[a-z0-9_]+\z/D', $this->database)) {
            $this->fail('Unsafe disposable database name.');
        }

        $this->originalConnection = config('database.connections.mysql');
        DB::connection('mysql')->statement("CREATE DATABASE `{$this->database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $this->created = true;

        $connection = $this->originalConnection;
        $connection['database'] = $this->database;
        Config::set('database.connections.mysql', $connection);
        DB::purge('mysql');
    }

    protected function tearDown(): void
    {
        DB::purge('mysql');
        if (isset($this->originalConnection)) {
            Config::set('database.connections.mysql', $this->originalConnection);
            if ($this->created) {
                DB::connection('mysql')->statement("DROP DATABASE `{$this->database}`");
            }
        }

        parent::tearDown();
    }

    public function test_exact_mapping_runs_on_fresh_mysql_and_preserves_audit_history(): void
    {
        Schema::connection('mysql')->create('migrations', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('migration');
            $table->integer('batch');
        });
        Schema::connection('mysql')->create('schools', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->string('database_name')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        DB::connection('mysql')->table('schools')->insert([
            ['id' => 15, 'name' => 'Zixuan', 'code' => 'SCH202615', 'database_name' => 'eschool_saas_15_zixuan', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 17, 'name' => 'Bahan', 'code' => 'SCH202616', 'database_name' => 'eschool_saas_17_bahan', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 19, 'name' => 'Timecity', 'code' => 'SCH202619', 'database_name' => 'eschool_saas_19_timecitys', 'created_at' => now(), 'updated_at' => now()],
        ]);

        (require database_path('migrations/2026_09_11_000001_finalize_school_code_identity.php'))->up();
        DB::connection('mysql')->table('migrations')->insert([
            'migration' => '2026_09_11_000001_finalize_school_code_identity',
            'batch' => 1,
        ]);

        $this->artisan('centralization:migrate-school-codes')->assertExitCode(0);
        $this->artisan('centralization:migrate-school-codes', ['--execute' => true])->assertExitCode(0);

        $this->assertSame('MMBOWEN02', DB::connection('mysql')->table('schools')->where('id', 17)->value('code'));
        $this->assertSame('MMBOWEN03', DB::connection('mysql')->table('schools')->where('id', 19)->value('code'));
        $this->assertSame(1, DB::connection('mysql')->table('school_code_history')->where('legacy_code', 'SCH202616')->count());
        $this->assertSame(1, DB::connection('mysql')->table('school_code_history')->where('legacy_code', 'SCH202619')->count());
        $this->assertSame(4, (int) DB::connection('mysql')->table('school_code_sequences')->where('prefix', 'MMBOWEN')->value('next_number'));
    }
}
