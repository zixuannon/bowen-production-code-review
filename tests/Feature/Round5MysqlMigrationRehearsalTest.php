<?php

namespace Tests\Feature;

use App\Services\LegacySchemaIntegrityService;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/** Opt-in disposable MariaDB/MySQL DDL rehearsal; never points at Production. */
final class Round5MysqlMigrationRehearsalTest extends TestCase
{
    private string $database;
    private array $schoolConnection;
    private string $defaultConnection;
    private bool $created = false;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('ROUND5_MYSQL_REHEARSAL') !== '1') $this->markTestSkipped('Set ROUND5_MYSQL_REHEARSAL=1 for disposable MySQL rehearsal.');
        $host = (string) config('database.connections.mysql.host');
        if (!in_array($host, ['127.0.0.1','localhost'], true)) $this->fail("Disposable rehearsal refuses non-local MySQL host: $host");
        $this->database = 'eschool_round5_'.getmypid().'_'.bin2hex(random_bytes(4));
        if (!preg_match('/^eschool_round5_[a-z0-9_]+$/', $this->database)) $this->fail('Unsafe disposable database name.');
        $this->schoolConnection = config('database.connections.school');
        $this->defaultConnection = DB::getDefaultConnection();
        DB::connection('mysql')->statement("CREATE DATABASE `{$this->database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $this->created = true;
        $connection = config('database.connections.mysql'); $connection['database'] = $this->database;
        Config::set('database.connections.school', $connection); DB::purge('school'); DB::setDefaultConnection('school');
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection($this->defaultConnection); DB::purge('school'); Config::set('database.connections.school', $this->schoolConnection);
        if ($this->created) DB::connection('mysql')->statement("DROP DATABASE `{$this->database}`");
        parent::tearDown();
    }

    public function test_fresh_schema_orphan_preflight_and_exact_constraints(): void
    {
        Schema::connection('school')->create('migrations', function (Blueprint $table): void { $table->id(); $table->string('migration'); $table->integer('batch'); });
        Schema::connection('school')->create('users', function (Blueprint $table): void { $table->id(); $table->unsignedBigInteger('school_id')->nullable(); });
        Schema::connection('school')->create('students', function (Blueprint $table): void { $table->id(); $table->unsignedBigInteger('user_id'); $table->unsignedBigInteger('school_id'); });
        (require database_path('migrations/schools/2026_06_22_000001_create_bank_accounts_table.php'))->up();
        (require database_path('migrations/schools/2026_06_25_000001_create_bank_transfers_table.php'))->up();
        (require database_path('migrations/schools/2026_08_10_000004_add_audit_fields_to_bank_accounts.php'))->up();
        (require database_path('migrations/schools/2026_09_03_000001_create_student_import_identities_table.php'))->up();

        DB::connection('school')->table('users')->insert([['id'=>1,'school_id'=>15],['id'=>2,'school_id'=>15],['id'=>3,'school_id'=>15],['id'=>4,'school_id'=>15],['id'=>5,'school_id'=>15]]);
        DB::connection('school')->table('students')->insert([['id'=>10,'user_id'=>2,'school_id'=>15],['id'=>11,'user_id'=>3,'school_id'=>15],['id'=>12,'user_id'=>4,'school_id'=>15],['id'=>13,'user_id'=>5,'school_id'=>15]]);
        DB::connection('school')->table('student_import_identities')->insert(['school_id'=>15,'student_code'=>'00125','student_id'=>10,'user_id'=>2,'created_by'=>1,'created_at'=>now(),'updated_at'=>now()]);
        DB::connection('school')->table('bank_accounts')->insert([
            ['id'=>20,'school_id'=>15,'account_name'=>'Cash','created_by'=>1,'updated_by'=>1,'created_at'=>now(),'updated_at'=>now()],
            ['id'=>21,'school_id'=>15,'account_name'=>'Bank','created_by'=>1,'updated_by'=>1,'created_at'=>now(),'updated_at'=>now()],
        ]);
        DB::connection('school')->table('bank_transfers')->insert([
            'school_id'=>15,'from_account_id'=>999,'to_account_id'=>21,'amount'=>9,'transfer_date'=>'2026-09-11','reference_no'=>'ORPHAN','status'=>'completed','created_by'=>1,'created_at'=>now(),'updated_at'=>now(),
        ]);
        try {
            app(LegacySchemaIntegrityService::class)->assertPreflightClean();
            $this->fail('Orphan transfer account must block DDL before ALTER.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('transfer_orphan_from', $exception->getMessage());
        }
        DB::connection('school')->table('bank_transfers')->where('reference_no','ORPHAN')->delete();
        DB::connection('school')->table('bank_transfers')->insert([
            ['school_id'=>15,'from_account_id'=>20,'to_account_id'=>21,'amount'=>10,'transfer_date'=>'2026-09-11','reference_no'=>'DUP','status'=>'completed','created_by'=>1,'created_at'=>now(),'updated_at'=>now()],
            ['school_id'=>15,'from_account_id'=>20,'to_account_id'=>21,'amount'=>11,'transfer_date'=>'2026-09-11','reference_no'=>'DUP','status'=>'completed','created_by'=>1,'created_at'=>now(),'updated_at'=>now()],
        ]);

        try {
            app(LegacySchemaIntegrityService::class)->assertPreflightClean();
            $this->fail('Duplicate transfer references must block DDL before ALTER.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('transfer_duplicate_reference', $exception->getMessage());
        }
        DB::connection('school')->table('bank_transfers')->where('reference_no','DUP')->orderByDesc('id')->limit(1)->delete();

        $round5Migration = require database_path('migrations/schools/'.LegacySchemaIntegrityService::ROUND5_MIGRATION.'.php');
        $round5Migration->up();
        $this->assertTrue(app(LegacySchemaIntegrityService::class)->round5SchemaComplete());
        $this->assertConstraintRejects(fn () => DB::connection('school')->table('student_import_identities')->insert(['school_id'=>15,'student_code'=>'00125','student_id'=>11,'user_id'=>3,'created_at'=>now(),'updated_at'=>now()]));
        $this->assertConstraintRejects(fn () => DB::connection('school')->table('bank_transfers')->insert(['school_id'=>15,'from_account_id'=>20,'to_account_id'=>20,'amount'=>10,'transfer_date'=>'2026-09-11','status'=>'completed','created_at'=>now(),'updated_at'=>now()]));
        $this->assertConstraintRejects(fn () => DB::connection('school')->table('bank_transfers')->insert(['school_id'=>15,'from_account_id'=>20,'to_account_id'=>21,'amount'=>0,'transfer_date'=>'2026-09-11','status'=>'completed','created_at'=>now(),'updated_at'=>now()]));
        $this->assertConcurrentStudentCodeHasExactlyOneWinner();
        $round5Migration->down();
        $this->assertFalse(app(LegacySchemaIntegrityService::class)->round5SchemaPresent());
        $round5Migration->up();
        $this->assertTrue(app(LegacySchemaIntegrityService::class)->round5SchemaComplete());
        DB::connection('school')->statement('ALTER TABLE bank_transfers DROP CONSTRAINT '.LegacySchemaIntegrityService::CHECKS[4]);
        $this->assertTrue(app(LegacySchemaIntegrityService::class)->round5SchemaPresent());
        $this->assertFalse(app(LegacySchemaIntegrityService::class)->round5SchemaComplete());
    }

    private function assertConstraintRejects(callable $write): void
    {
        try { $write(); $this->fail('Database constraint did not reject invalid write.'); }
        catch (QueryException) { $this->addToAssertionCount(1); }
    }

    private function assertConcurrentStudentCodeHasExactlyOneWinner(): void
    {
        if (!function_exists('pcntl_fork')) $this->markTestSkipped('pcntl is required for the concurrent MySQL delivery test.');
        $prefix = sys_get_temp_dir().'/round5-concurrent-'.bin2hex(random_bytes(6));
        $barrier = "$prefix.go"; $results = ["$prefix.0", "$prefix.1"];
        $connection = config('database.connections.school');
        DB::disconnect('school');
        $children = [];
        foreach ([0=>[12,4], 1=>[13,5]] as $slot => [$studentId,$userId]) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                while (!is_file($barrier)) usleep(1000);
                try {
                    $pdo = new \PDO(
                        "mysql:host={$connection['host']};port={$connection['port']};dbname={$this->database};charset=utf8mb4",
                        $connection['username'], $connection['password'], [\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION]
                    );
                    $statement = $pdo->prepare('INSERT INTO student_import_identities (school_id,student_code,student_id,user_id,created_by,created_at,updated_at) VALUES (15,?,?,?,?,NOW(),NOW())');
                    $statement->execute(['CONCURRENT-001',$studentId,$userId,1]);
                    file_put_contents($results[$slot], 'success');
                } catch (\PDOException) {
                    file_put_contents($results[$slot], 'duplicate');
                }
                exit(0);
            }
            if ($pid < 0) $this->fail('Unable to fork concurrent import worker.');
            $children[] = $pid;
        }
        file_put_contents($barrier, 'go');
        foreach ($children as $pid) pcntl_waitpid($pid, $status);
        $outcomes = array_map(fn (string $path): string => (string) file_get_contents($path), $results); sort($outcomes);
        @unlink($barrier); foreach ($results as $path) @unlink($path);
        DB::reconnect('school');
        $this->assertSame(['duplicate','success'], $outcomes);
        $this->assertSame(1, DB::connection('school')->table('student_import_identities')->where('school_id',15)->where('student_code','CONCURRENT-001')->count());
    }
}
