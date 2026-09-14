<?php

namespace Tests\Feature;

use App\Models\Students;
use App\Models\User;
use App\Services\StudentCodeService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Opt-in disposable local MySQL rehearsal for DDL and row-lock concurrency. */
final class StudentCodeMysqlRehearsalTest extends TestCase
{
    private string $database;
    private array $schoolConnection;
    private string $defaultConnection;
    private bool $created = false;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('STUDENT_CODE_MYSQL_REHEARSAL') !== '1') $this->markTestSkipped('Set STUDENT_CODE_MYSQL_REHEARSAL=1 for disposable local MySQL rehearsal.');
        $host = (string) config('database.connections.mysql.host');
        if (!in_array($host, ['127.0.0.1', 'localhost'], true)) $this->fail("Disposable rehearsal refuses non-local MySQL host: {$host}");
        if (!function_exists('pcntl_fork')) $this->markTestSkipped('pcntl is required for the concurrent Student Code rehearsal.');
        $this->database = 'eschool_student_code_'.getmypid().'_'.bin2hex(random_bytes(4));
        if (!preg_match('/\Aeschool_student_code_[a-z0-9_]+\z/D', $this->database)) $this->fail('Unsafe disposable database name.');
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

    public function test_fresh_mysql_sequence_and_concurrent_create_and_import_are_exactly_once(): void
    {
        Schema::connection('school')->create('students', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('user_id'); $table->unsignedBigInteger('school_id'); $table->softDeletes();
        });
        (require database_path('migrations/schools/2026_09_03_000001_create_student_import_identities_table.php'))->up();
        (require database_path('migrations/schools/2026_09_14_000001_create_student_code_sequences.php'))->up();
        DB::connection('school')->table('students')->insert([
            ['id' => 1, 'user_id' => 101, 'school_id' => 19],
            ['id' => 2, 'user_id' => 102, 'school_id' => 19],
            ['id' => 3, 'user_id' => 103, 'school_id' => 19],
            ['id' => 4, 'user_id' => 104, 'school_id' => 19],
        ]);

        $this->runConcurrent([[1, null], [2, null]], ['success:000001', 'success:000002']);
        $this->runConcurrent([[3, 'IMPORT-ONE'], [4, 'IMPORT-ONE']], ['duplicate', 'success:000003']);
        $this->assertSame(3, DB::connection('school')->table('student_import_identities')->where('school_id', 19)->count());
        $this->assertSame(1, DB::connection('school')->table('student_import_identities')->where('school_id', 19)->where('import_reference', 'IMPORT-ONE')->count());
        $this->assertSame(4, (int) DB::connection('school')->table('student_code_sequences')->where('school_id', 19)->value('next_number'));
    }

    /** @param list<array{0:int,1:?string}> $jobs @param list<string> $expected */
    private function runConcurrent(array $jobs, array $expected): void
    {
        $prefix = sys_get_temp_dir().'/student-code-concurrent-'.bin2hex(random_bytes(6));
        $barrier = $prefix.'.go'; $results = [$prefix.'.0', $prefix.'.1'];
        DB::disconnect('school');
        $children = [];
        foreach ($jobs as $slot => [$studentId, $reference]) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                while (!is_file($barrier)) usleep(1000);
                try {
                    DB::purge('school'); DB::setDefaultConnection('school');
                    $actor = new User(); $actor->id = 900 + $slot;
                    $identity = app(StudentCodeService::class)->assignGenerated(Students::query()->findOrFail($studentId), $actor, $reference);
                    file_put_contents($results[$slot], 'success:'.$identity->student_code);
                } catch (\Illuminate\Validation\ValidationException) {
                    file_put_contents($results[$slot], 'duplicate');
                } catch (\Throwable $exception) {
                    file_put_contents($results[$slot], 'error:'.get_class($exception).':'.$exception->getMessage());
                }
                exit(0);
            }
            if ($pid < 0) $this->fail('Unable to fork concurrent Student Code worker.');
            $children[] = $pid;
        }
        file_put_contents($barrier, 'go');
        foreach ($children as $pid) pcntl_waitpid($pid, $status);
        $outcomes = array_map(fn (string $path): string => (string) file_get_contents($path), $results); sort($outcomes); sort($expected);
        @unlink($barrier); foreach ($results as $path) @unlink($path);
        DB::reconnect('school');
        $this->assertSame($expected, $outcomes);
    }
}
