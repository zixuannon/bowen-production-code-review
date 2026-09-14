<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use App\Services\ProductionMigrationGuard;

final class StudentCodeRedesignContractTest extends TestCase
{
    private array $schoolConnection;
    private string $schoolDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->schoolConnection = config('database.connections.school');
        $this->schoolDatabase = tempnam(sys_get_temp_dir(), 'student-code-redesign-');
        Config::set('database.connections.school', ['driver' => 'sqlite', 'database' => $this->schoolDatabase, 'prefix' => '', 'foreign_key_constraints' => true]);
        DB::purge('school');
        Schema::connection('school')->create('students', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('user_id'); $table->unsignedBigInteger('school_id');
        });
        (require database_path('migrations/schools/2026_09_03_000001_create_student_import_identities_table.php'))->up();
    }

    protected function tearDown(): void
    {
        DB::purge('school');
        Config::set('database.connections.school', $this->schoolConnection);
        @unlink($this->schoolDatabase);
        parent::tearDown();
    }

    public function test_additive_schema_seeds_above_existing_generated_codes_and_enforces_import_idempotency(): void
    {
        DB::connection('school')->table('student_import_identities')->insert([
            ['school_id' => 7, 'student_code' => '000009', 'student_id' => 1, 'user_id' => 11, 'created_at' => now(), 'updated_at' => now()],
            ['school_id' => 7, 'student_code' => 'LEGACY-2026', 'student_id' => 2, 'user_id' => 12, 'created_at' => now(), 'updated_at' => now()],
        ]);

        (require database_path('migrations/schools/2026_09_14_000001_create_student_code_sequences.php'))->up();

        $this->assertTrue(Schema::connection('school')->hasColumn('student_import_identities', 'import_reference'));
        $this->assertTrue(Schema::connection('school')->hasTable('student_code_sequences'));
        $this->assertSame(10, (int) DB::connection('school')->table('student_code_sequences')->where('school_id', 7)->value('next_number'));

        DB::connection('school')->table('student_import_identities')->where('id', 1)->update(['import_reference' => 'ROW-001']);
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::connection('school')->table('student_import_identities')->where('id', 2)->update(['import_reference' => 'ROW-001']);
    }

    public function test_partial_sequence_schema_fails_before_identity_schema_write(): void
    {
        Schema::connection('school')->create('student_code_sequences', function (Blueprint $table): void {
            $table->unsignedBigInteger('school_id');
        });
        try {
            (require database_path('migrations/schools/2026_09_14_000001_create_student_code_sequences.php'))->up();
            $this->fail('A partial sequence table must fail closed.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('partial schema', $exception->getMessage());
        }
        $this->assertFalse(Schema::connection('school')->hasColumn('student_import_identities', 'import_reference'));
        $this->assertFalse(Schema::connection('school')->hasColumn('student_code_sequences', 'next_number'));
    }

    public function test_creation_import_finance_and_api_contracts_use_canonical_student_code(): void
    {
        $studentController = file_get_contents(app_path('Http/Controllers/StudentController.php'));
        $import = file_get_contents(app_path('Services/StudentImportV2Service.php'));
        $paymentImport = file_get_contents(app_path('Services/CentralFinancePaymentImportService.php'));
        $api = file_get_contents(app_path('Http/Controllers/Api/Dify/DifyApiController.php'));
        $guard = file_get_contents(app_path('Services/ProductionMigrationGuard.php'));

        $this->assertStringNotContainsString("'student_code' => 'required|string|max:100'", $studentController);
        $this->assertGreaterThanOrEqual(3, substr_count($studentController, 'assignGenerated($student, Auth::user())'));
        $this->assertStringContainsString("'import_reference'", $import);
        $this->assertStringContainsString('assignGenerated($student, $actor', $import);
        $this->assertStringContainsString("where('student_code', \$data['student_code'])", $paymentImport);
        $this->assertStringNotContainsString("where('admission_no', \$data['student_code'])", $paymentImport);
        $this->assertStringContainsString("'student_code'             => \$student->studentImportIdentity?->student_code", $api);
        $this->assertStringContainsString("orWhereHas('studentImportIdentity'", $api);
        $this->assertStringContainsString("'student-code:migrate'", $guard);
    }

    public function test_production_guard_allows_only_the_exact_student_code_migration(): void
    {
        $path = database_path('migrations/schools/2026_09_14_000001_create_student_code_sequences.php');
        app(ProductionMigrationGuard::class)->assertAllowed('migrate', 'student-code:migrate', [$path], true, true);
        $this->addToAssertionCount(1);

        $this->expectException(\RuntimeException::class);
        app(ProductionMigrationGuard::class)->assertAllowed(
            'migrate',
            'student-code:migrate',
            [database_path('migrations/schools/2026_09_03_000001_create_student_import_identities_table.php')],
            true,
            true,
        );
    }
}
