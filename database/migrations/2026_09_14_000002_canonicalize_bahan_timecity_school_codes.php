<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TARGETS = [
        [
            'legacy' => 'SCH202616',
            'canonical' => 'MMBOWEN02',
            'database' => 'eschool_saas_17_bahan',
            'reason' => 'Bahan Central Finance canonical School Code setup',
        ],
        [
            'legacy' => 'SCH202619',
            'canonical' => 'MMBOWEN03',
            'database' => 'eschool_saas_19_timecitys',
            'reason' => 'Timecity Central Finance canonical School Code setup',
        ],
    ];

    public function up(): void
    {
        $this->preflight();

        DB::connection('mysql')->transaction(function (): void {
            $connection = DB::connection('mysql');
            $schools = $connection->table('schools');
            $sequence = $connection->table('school_code_sequences')
                ->where('prefix', 'MMBOWEN')->lockForUpdate()->first();
            if ($sequence === null) {
                throw new RuntimeException('Canonical School Code sequence is missing; migration refused.');
            }

            foreach (self::TARGETS as $target) {
                $school = (clone $schools)->where('code', $target['legacy'])
                    ->where('database_name', $target['database'])->whereNull('deleted_at')
                    ->lockForUpdate()->sole(['id', 'code', 'database_name']);
                $now = now();
                $connection->table('school_code_history')->insert([
                    'school_id' => $school->id,
                    'legacy_code' => $target['legacy'],
                    'canonical_code' => $target['canonical'],
                    'change_reason' => $target['reason'],
                    'changed_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $updated = (clone $schools)->where('id', $school->id)
                    ->where('code', $target['legacy'])->where('database_name', $target['database'])
                    ->update(['code' => $target['canonical'], 'updated_at' => $now]);
                if ($updated !== 1) {
                    throw new RuntimeException('Canonical School Code update lost ownership; migration refused.');
                }
            }

            $connection->table('school_code_sequences')->where('prefix', 'MMBOWEN')->update([
                'next_number' => max(4, (int) $sequence->next_number),
                'updated_at' => now(),
            ]);
        });
    }

    private function preflight(): void
    {
        if (!Schema::connection('mysql')->hasColumns('school_code_history', [
            'school_id', 'legacy_code', 'canonical_code', 'change_reason', 'changed_at',
        ]) || !Schema::connection('mysql')->hasColumns('school_code_sequences', ['prefix', 'next_number'])) {
            throw new RuntimeException('Canonical School Code identity schema is incomplete; migration refused.');
        }

        $hasUniqueSchoolCode = collect(Schema::connection('mysql')->getIndexes('schools'))->contains(
            static fn (array $index): bool => ($index['unique'] ?? false) === true
                && array_values($index['columns'] ?? []) === ['code']
        );
        if (!$hasUniqueSchoolCode) {
            throw new RuntimeException('Canonical School Code requires a unique schools.code constraint; migration refused.');
        }

        $connection = DB::connection('mysql');
        foreach (self::TARGETS as $target) {
            $legacy = $connection->table('schools')->where('code', $target['legacy'])
                ->whereNull('deleted_at')->get(['id', 'database_name']);
            // A soft-deleted School still owns its unique code. Treat it as a
            // conflict before any write instead of relying on the later key
            // violation to abort the transaction.
            $canonical = $connection->table('schools')->where('code', $target['canonical'])
                ->get(['id', 'database_name']);
            if ($legacy->count() !== 1 || $canonical->isNotEmpty()
                || !hash_equals($target['database'], (string) $legacy->sole()->database_name)
                || $connection->table('school_code_history')->where('legacy_code', $target['legacy'])->exists()
                || $connection->table('school_code_history')->where('canonical_code', $target['canonical'])->exists()) {
                throw new RuntimeException('Bahan/Timecity School Code ownership or audit history is not eligible; migration refused.');
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Bahan/Timecity canonical School Codes are forward-only; use an audited forward fix.');
    }
};
