<?php

namespace Tests\Feature;

use App\Models\School;
use App\Services\SchoolCodeService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

final class SchoolCodeMappingTest extends TestCase
{
    private array $originalConnection;
    private string $database;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConnection = Config::get('database.connections.mysql');
        $this->database = tempnam(sys_get_temp_dir(), 'school-code-map-');
        Config::set('database.connections.mysql', [
            'driver' => 'sqlite',
            'database' => $this->database,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        DB::purge('mysql');

        Schema::connection('mysql')->create('schools', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->string('database_name')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        DB::connection('mysql')->table('schools')->insert([
            'id' => 15,
            'name' => 'Zixuan',
            'code' => 'SCH202615',
            'database_name' => 'eschool_saas_15_zixuan',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        DB::purge('mysql');
        Config::set('database.connections.mysql', $this->originalConnection);
        @unlink($this->database);
        parent::tearDown();
    }

    public function test_migration_makes_mmbowen01_canonical_and_keeps_legacy_code_audit_only(): void
    {
        $this->migration()->up();

        $this->assertSame('MMBOWEN01', DB::connection('mysql')->table('schools')->where('id', 15)->value('code'));
        $this->assertSame([
            'legacy_code' => 'SCH202615',
            'canonical_code' => 'MMBOWEN01',
        ], (array) DB::connection('mysql')->table('school_code_history')->where('school_id', 15)->first(['legacy_code', 'canonical_code']));
        $this->assertSame(15, app(SchoolCodeService::class)->resolveCanonical('mmbowen01')?->id);
        $this->assertNull(app(SchoolCodeService::class)->resolveCanonical('SCH202615'));
        $this->assertSame('MMBOWEN02', app(SchoolCodeService::class)->previewNextCode());
    }

    public function test_allocator_and_super_admin_claim_are_locked_normalized_and_zero_padded(): void
    {
        $this->migration()->up();

        DB::connection('mysql')->transaction(function (): void {
            $this->assertSame('MMBOWEN02', app(SchoolCodeService::class)->allocateNextCode());
            $this->assertSame('MMBOWEN09', app(SchoolCodeService::class)->claimRequestedCode(' mmbowen09 '));
        });

        $this->assertSame(10, (int) DB::connection('mysql')->table('school_code_sequences')->where('prefix', 'MMBOWEN')->value('next_number'));
    }

    public function test_invalid_deprecated_and_duplicate_codes_fail_closed(): void
    {
        $this->migration()->up();
        $service = app(SchoolCodeService::class);

        foreach (['SCH202615', 'MMBOWEN1', 'MMBOWEN-02', 'BOWEN02'] as $invalid) {
            try {
                DB::connection('mysql')->transaction(fn (): string => $service->claimRequestedCode($invalid));
                $this->fail("{$invalid} should be rejected.");
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(RuntimeException::class);
        DB::connection('mysql')->transaction(fn (): string => $service->claimRequestedCode('MMBOWEN01'));
    }

    public function test_school_model_normalizes_canonical_code_before_storage(): void
    {
        $school = School::on('mysql')->create([
            'name' => 'Normalized',
            'code' => ' mmbowen22 ',
            'database_name' => 'local_normalized',
        ]);

        $this->assertSame('MMBOWEN22', $school->getRawOriginal('code'));
    }

    public function test_migration_uses_audited_mapping_not_destructive_string_replacement(): void
    {
        $source = file_get_contents(database_path('migrations/2026_09_11_000001_finalize_school_code_identity.php'));

        $this->assertStringContainsString('school_code_history', $source);
        $this->assertStringContainsString('change_reason', $source);
        $this->assertStringNotContainsString('REPLACE(', strtoupper($source));
        $this->assertStringNotContainsString('TRUNCATE', strtoupper($source));
    }

    public function test_wrong_zixuan_tenant_fails_before_any_identity_ddl(): void
    {
        DB::connection('mysql')->table('schools')->where('id', 15)->update(['database_name' => 'wrong_tenant']);

        try {
            $this->migration()->up();
            $this->fail('Mismatched Zixuan tenant ownership should fail closed.');
        } catch (RuntimeException) {
            $this->assertFalse(Schema::connection('mysql')->hasTable('school_code_history'));
            $this->assertFalse(Schema::connection('mysql')->hasTable('school_code_sequences'));
        }
    }

    public function test_missing_canonical_code_unique_constraint_fails_before_any_identity_ddl(): void
    {
        Schema::connection('mysql')->table('schools', function (Blueprint $table): void {
            $table->dropUnique(['code']);
        });

        try {
            $this->migration()->up();
            $this->fail('Missing schools.code uniqueness should fail closed.');
        } catch (RuntimeException) {
            $this->assertFalse(Schema::connection('mysql')->hasTable('school_code_history'));
            $this->assertFalse(Schema::connection('mysql')->hasTable('school_code_sequences'));
        }
    }

    public function test_partial_identity_schema_fails_closed_without_creating_the_other_table(): void
    {
        Schema::connection('mysql')->create('school_code_history', function (Blueprint $table): void {
            $table->id();
        });

        try {
            $this->migration()->up();
            $this->fail('Partial School Code identity schema should fail closed.');
        } catch (RuntimeException) {
            $this->assertTrue(Schema::connection('mysql')->hasTable('school_code_history'));
            $this->assertFalse(Schema::connection('mysql')->hasTable('school_code_sequences'));
        }
    }

    public function test_canonical_identity_rollback_is_refused_to_preserve_audit_history(): void
    {
        $migration = $this->migration();
        $migration->up();

        try {
            $migration->down();
            $this->fail('Canonical School Code finalization must be forward-only.');
        } catch (RuntimeException) {
            $this->assertSame('MMBOWEN01', DB::connection('mysql')->table('schools')->where('id', 15)->value('code'));
            $this->assertSame(1, DB::connection('mysql')->table('school_code_history')->count());
        }
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_09_11_000001_finalize_school_code_identity.php');
    }
}
