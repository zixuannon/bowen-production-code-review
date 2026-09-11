<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const LEGACY_ZIXUAN = 'SCH202615';
    private const CANONICAL_ZIXUAN = 'MMBOWEN01';
    private const ZIXUAN_DATABASE = 'eschool_saas_15_zixuan';

    public function up(): void
    {
        if (Schema::connection('mysql')->hasTable('school_code_history') || Schema::connection('mysql')->hasTable('school_code_sequences')) {
            throw new RuntimeException('Partial School Code identity schema detected; migration refused.');
        }

        // Validate ownership and every existing MMBOWEN code before any DDL. MySQL
        // auto-commits DDL, so a bad production-shaped registry must leave no
        // partial identity tables behind.
        $this->preflightExistingRegistry();

        Schema::connection('mysql')->create('school_code_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->restrictOnDelete();
            $table->string('legacy_code', 64);
            $table->string('canonical_code', 64);
            $table->string('change_reason', 191);
            $table->timestamp('changed_at');
            $table->timestamps();
            $table->unique('legacy_code', 'school_code_history_legacy_unique');
            $table->unique('canonical_code', 'school_code_history_canonical_unique');
        });

        Schema::connection('mysql')->create('school_code_sequences', function (Blueprint $table): void {
            $table->string('prefix', 32)->primary();
            $table->unsignedBigInteger('next_number');
            $table->timestamps();
        });

        DB::connection('mysql')->transaction(function (): void {
            $schools = DB::connection('mysql')->table('schools');
            $legacy = (clone $schools)->where('code', self::LEGACY_ZIXUAN)->whereNull('deleted_at')->lockForUpdate()->get(['id', 'code', 'database_name']);
            $canonical = (clone $schools)->where('code', self::CANONICAL_ZIXUAN)->whereNull('deleted_at')->lockForUpdate()->get(['id', 'code', 'database_name']);

            if ($legacy->count() > 1 || $canonical->count() > 1 || ($legacy->isNotEmpty() && $canonical->isNotEmpty())) {
                throw new RuntimeException('Zixuan School Code ownership is ambiguous; migration refused.');
            }

            $target = $legacy->first() ?: $canonical->first();
            if ($target && !hash_equals(self::ZIXUAN_DATABASE, (string) $target->database_name)) {
                throw new RuntimeException('Zixuan School Code is not bound to the approved tenant database; migration refused.');
            }

            if ($target) {
                $now = now();
                DB::connection('mysql')->table('school_code_history')->insert([
                    'school_id' => $target->id,
                    'legacy_code' => self::LEGACY_ZIXUAN,
                    'canonical_code' => self::CANONICAL_ZIXUAN,
                    'change_reason' => 'Operational UX canonical School Code finalization',
                    'changed_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                if ($legacy->isNotEmpty()) {
                    $updated = (clone $schools)->where('id', $target->id)->where('code', self::LEGACY_ZIXUAN)->update([
                        'code' => self::CANONICAL_ZIXUAN,
                        'updated_at' => $now,
                    ]);
                    if ($updated !== 1) {
                        throw new RuntimeException('Zixuan canonical School Code update lost ownership; migration refused.');
                    }
                }
            }

            $next = 1;
            foreach ((clone $schools)->where('code', 'like', 'MMBOWEN%')->lockForUpdate()->pluck('code') as $code) {
                $normalized = strtoupper(trim((string) $code));
                if (!preg_match('/^MMBOWEN([0-9]{2,})$/', $normalized, $matches) || $normalized !== (string) $code) {
                    throw new RuntimeException('Existing MMBOWEN School Code is not canonical uppercase format; migration refused.');
                }
                $next = max($next, ((int) $matches[1]) + 1);
            }

            DB::connection('mysql')->table('school_code_sequences')->insert([
                'prefix' => 'MMBOWEN',
                'next_number' => $next,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    private function preflightExistingRegistry(): void
    {
        $hasUniqueCode = collect(Schema::connection('mysql')->getIndexes('schools'))->contains(
            static fn (array $index): bool => ($index['unique'] ?? false) === true
                && array_values($index['columns'] ?? []) === ['code']
        );
        if (!$hasUniqueCode) {
            throw new RuntimeException('Canonical School Code requires a unique schools.code constraint; migration refused.');
        }

        $schools = DB::connection('mysql')->table('schools');
        $legacy = (clone $schools)->where('code', self::LEGACY_ZIXUAN)->whereNull('deleted_at')->get(['id', 'database_name']);
        $canonical = (clone $schools)->where('code', self::CANONICAL_ZIXUAN)->whereNull('deleted_at')->get(['id', 'database_name']);

        if ($legacy->count() > 1 || $canonical->count() > 1 || ($legacy->isNotEmpty() && $canonical->isNotEmpty())) {
            throw new RuntimeException('Zixuan School Code ownership is ambiguous; migration refused.');
        }

        $target = $legacy->first() ?: $canonical->first();
        if ($target && !hash_equals(self::ZIXUAN_DATABASE, (string) $target->database_name)) {
            throw new RuntimeException('Zixuan School Code is not bound to the approved tenant database; migration refused.');
        }

        foreach ((clone $schools)->where('code', 'like', 'MMBOWEN%')->pluck('code') as $code) {
            $normalized = strtoupper(trim((string) $code));
            if (!preg_match('/^MMBOWEN[0-9]{2,}$/', $normalized) || $normalized !== (string) $code) {
                throw new RuntimeException('Existing MMBOWEN School Code is not canonical uppercase format; migration refused.');
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Canonical School Code finalization is forward-only; use an audited forward fix.');
    }
};
