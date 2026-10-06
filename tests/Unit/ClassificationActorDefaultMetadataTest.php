<?php

namespace Tests\Unit;

use App\Console\Commands\MigrateFinanceClassificationActors;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ClassificationActorDefaultMetadataTest extends TestCase
{
    public static function defaults(): array
    {
        return [
            'mysql sql null' => [null, false, true],
            'mariadb native null' => [null, true, true],
            'mariadb observed SQL NULL token' => ['NULL', true, true],
            'mysql literal NULL' => ['NULL', false, false],
            'mariadb quoted literal' => ["'NULL'", true, false],
            'mariadb double-quoted literal' => ['"NULL"', true, false],
            'unexpected lowercase' => ['null', true, false],
            'unexpected padding' => [' NULL ', true, false],
            'empty string' => ['', true, false],
            'zero' => [0, true, false],
            'string zero' => ['0', true, false],
            'false' => [false, true, false],
            'expression' => ['CURRENT_TIMESTAMP', true, false],
        ];
    }

    #[DataProvider('defaults')]
    public function test_only_native_null_and_mariadb_sql_null_metadata_are_allowed(mixed $default, bool $maria, bool $expected): void
    {
        self::assertSame($expected, MigrateFinanceClassificationActors::hasNullDefault($default, $maria));
    }
}
