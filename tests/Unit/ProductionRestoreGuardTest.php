<?php

namespace Tests\Unit;

use App\Support\ProductionRestoreGuard;
use RuntimeException;
use Tests\TestCase;

class ProductionRestoreGuardTest extends TestCase
{
    private ProductionRestoreGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->guard = new ProductionRestoreGuard();
    }

    public function test_only_existing_unregistered_disposable_target_passes(): void
    {
        $this->guard->assertTarget(
            'eschool_incident_disposable_zixuan_20260915',
            'sql_43_160_241_126',
            ['eschool_saas_15_zixuan'],
            ['sql_43_160_241_126', 'eschool_saas_15_zixuan', 'eschool_incident_disposable_zixuan_20260915'],
        );
        $this->guard->assertEmptyTarget(0);
        $this->addToAssertionCount(1);
    }

    public function test_nonempty_disposable_target_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->guard->assertEmptyTarget(1);
    }

    public function test_active_missing_and_unapproved_targets_fail_closed(): void
    {
        foreach (['eschool_saas_15_zixuan', 'sql_43_160_241_126', 'eschool_incident_disposable_missing', 'other_disposable'] as $target) {
            try {
                $this->guard->assertTarget($target, 'sql_43_160_241_126', ['eschool_saas_15_zixuan'],
                    ['sql_43_160_241_126', 'eschool_saas_15_zixuan']);
                $this->fail("Unsafe target {$target} passed.");
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_use_database_ddl_and_protected_qualified_sql_are_rejected(): void
    {
        foreach ([
            "USE `eschool_saas_15_zixuan`;\n",
            "/*!40101 USE `eschool_saas_15_zixuan` */;\n",
            "CREATE DATABASE eschool_saas_15_zixuan;\n",
            "CREATE\nDATABASE eschool_saas_15_zixuan;\n",
            "DROP DATABASE eschool_saas_15_zixuan;\n",
            "INSERT INTO `eschool_saas_15_zixuan`.`students` VALUES (1);\n",
            "INSERT INTO `eschool_saas_15_zixuan`\n.`students` VALUES (1);\n",
            "SET SQL_LOG_BIN=0;\n",
            "PREPARE stmt FROM @unsafe_sql;\n",
            "SELECT 'x' INTO OUTFILE '/tmp/unsafe';\n",
            "\0CREATE TABLE students (id int);\n",
        ] as $sql) {
            $path = $this->sqlFile($sql);
            try {
                $this->guard->assertSqlFile($path, ['eschool_saas_15_zixuan']);
                $this->fail('Unsafe restore SQL passed.');
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            } finally {
                unlink($path);
            }
        }
    }

    public function test_corrupted_gzip_is_rejected_before_any_restore(): void
    {
        $gzip = $this->sqlFile(substr(gzencode("CREATE TABLE `students` (`id` int);\n"), 0, -8)).'.gz';
        rename(substr($gzip, 0, -3), $gzip);
        try {
            $this->expectException(RuntimeException::class);
            $this->guard->assertSqlFile($gzip, ['eschool_saas_15_zixuan']);
        } finally {
            unlink($gzip);
        }
    }

    public function test_safe_plain_and_gzip_dumps_pass_without_writing_to_a_database(): void
    {
        $sql = "CREATE TABLE `students` (`id` bigint PRIMARY KEY);\nINSERT INTO `students` VALUES (1);\n";
        $plain = $this->sqlFile($sql);
        $gzip = $this->sqlFile(gzencode($sql)).'.gz';
        rename(substr($gzip, 0, -3), $gzip);
        try {
            $this->guard->assertSqlFile($plain, ['eschool_saas_15_zixuan']);
            $this->guard->assertSqlFile($gzip, ['eschool_saas_15_zixuan']);
            $this->addToAssertionCount(2);
        } finally {
            unlink($plain);
            unlink($gzip);
        }
    }

    private function sqlFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'eschool_restore_guard_');
        file_put_contents($path, $contents);
        return $path;
    }
}
