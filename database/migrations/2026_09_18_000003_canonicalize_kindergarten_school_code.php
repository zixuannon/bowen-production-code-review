<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** One audited, forward-only identity correction for the registered Bowen kindergarten tenant. */
return new class extends Migration
{
    private const SCHOOL_ID = 20;
    private const LEGACY_CODE = 'SCH202620';
    private const CANONICAL_CODE = 'MMBOWEN04';
    private const DATABASE = 'eschool_saas_20_';
    private const REASON = 'Bowen Kindergarten Central Finance canonical School Code preparation';

    public function up(): void
    {
        $this->preflight();

        DB::connection('mysql')->transaction(function (): void {
            $connection = DB::connection('mysql');
            $school = $connection->table('schools')->where('id', self::SCHOOL_ID)
                ->where('code', self::LEGACY_CODE)->where('database_name', self::DATABASE)
                ->whereNull('deleted_at')->lockForUpdate()->sole(['id', 'code', 'database_name']);
            $sequence = $connection->table('school_code_sequences')->where('prefix', 'MMBOWEN')->lockForUpdate()->first();
            if ($sequence === null) throw new \RuntimeException('Canonical School Code sequence is missing; migration refused.');

            $now = now();
            $connection->table('school_code_history')->insert([
                'school_id' => $school->id,
                'legacy_code' => self::LEGACY_CODE,
                'canonical_code' => self::CANONICAL_CODE,
                'change_reason' => self::REASON,
                'changed_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $updated = $connection->table('schools')->where('id', self::SCHOOL_ID)
                ->where('code', self::LEGACY_CODE)->where('database_name', self::DATABASE)
                ->update(['code' => self::CANONICAL_CODE, 'updated_at' => $now]);
            if ($updated !== 1) throw new \RuntimeException('Kindergarten School Code update lost tenant ownership; migration refused.');

            $connection->table('school_code_sequences')->where('prefix', 'MMBOWEN')->update([
                'next_number' => max(5, (int) $sequence->next_number), 'updated_at' => $now,
            ]);
        });
    }

    private function preflight(): void
    {
        if (!Schema::connection('mysql')->hasColumns('school_code_history', ['school_id', 'legacy_code', 'canonical_code', 'change_reason', 'changed_at'])
            || !Schema::connection('mysql')->hasColumns('school_code_sequences', ['prefix', 'next_number'])) {
            throw new \RuntimeException('Canonical School Code identity schema is incomplete; migration refused.');
        }
        $uniqueCode = collect(Schema::connection('mysql')->getIndexes('schools'))->contains(
            static fn (array $index): bool => ($index['unique'] ?? false) === true && array_values($index['columns'] ?? []) === ['code']
        );
        if (!$uniqueCode) throw new \RuntimeException('Canonical School Code requires a unique schools.code constraint; migration refused.');

        $connection = DB::connection('mysql');
        $school = $connection->table('schools')->where('id', self::SCHOOL_ID)->where('code', self::LEGACY_CODE)
            ->whereNull('deleted_at')->get(['id', 'database_name']);
        $collision = $connection->table('schools')->where('code', self::CANONICAL_CODE)->exists();
        $history = $connection->table('school_code_history')->where('legacy_code', self::LEGACY_CODE)->orWhere('canonical_code', self::CANONICAL_CODE)->exists();
        if ($school->count() !== 1 || !hash_equals(self::DATABASE, (string) $school->sole()->database_name) || $collision || $history) {
            throw new \RuntimeException('Kindergarten School Code ownership, collision, or audit history is not eligible; migration refused.');
        }
    }

    public function down(): void
    {
        throw new \RuntimeException('Kindergarten canonical School Code is forward-only; use an audited forward fix.');
    }
};
