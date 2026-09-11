<?php

use App\Services\LegacySchemaIntegrityService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $integrity = app(LegacySchemaIntegrityService::class);
        if (!$integrity->round5BaseComplete()) {
            throw new RuntimeException('Round 5 requires the complete legacy identity, Bank Account, and Bank Transfer base schema.');
        }

        // Every destructive data condition is checked before the first ALTER.
        // The migration never repairs, deletes, renames, or merges Production data.
        $integrity->assertPreflightClean();

        Schema::connection('school')->table('students', function (Blueprint $table): void {
            $table->unique(['id', 'school_id'], LegacySchemaIntegrityService::INDEXES[0]);
        });
        Schema::connection('school')->table('users', function (Blueprint $table): void {
            $table->unique(['id', 'school_id'], LegacySchemaIntegrityService::INDEXES[1]);
        });
        Schema::connection('school')->table('bank_accounts', function (Blueprint $table): void {
            $table->unique(['id', 'school_id'], LegacySchemaIntegrityService::INDEXES[2]);
        });
        Schema::connection('school')->table('bank_transfers', function (Blueprint $table): void {
            // Preserve cancelled/soft-deleted audit history while keeping the
            // live idempotency boundary database-enforced. Multiple NULLs are
            // permitted by MariaDB/MySQL unique indexes.
            $table->string(LegacySchemaIntegrityService::ACTIVE_TRANSFER_REFERENCE, 100)
                ->nullable()
                ->storedAs('CASE WHEN deleted_at IS NULL THEN reference_no ELSE NULL END')
                ->after('reference_no');
            $table->unique(
                ['school_id', LegacySchemaIntegrityService::ACTIVE_TRANSFER_REFERENCE],
                LegacySchemaIntegrityService::INDEXES[3]
            );
        });

        Schema::connection('school')->table('student_import_identities', function (Blueprint $table): void {
            $table->foreign(['student_id', 'school_id'], LegacySchemaIntegrityService::FOREIGN_KEYS[0])
                ->references(['id', 'school_id'])->on('students')->restrictOnDelete();
            $table->foreign(['user_id', 'school_id'], LegacySchemaIntegrityService::FOREIGN_KEYS[1])
                ->references(['id', 'school_id'])->on('users')->restrictOnDelete();
            $table->foreign(['created_by', 'school_id'], LegacySchemaIntegrityService::FOREIGN_KEYS[2])
                ->references(['id', 'school_id'])->on('users')->restrictOnDelete();
        });
        Schema::connection('school')->table('bank_accounts', function (Blueprint $table): void {
            $table->foreign(['created_by', 'school_id'], LegacySchemaIntegrityService::FOREIGN_KEYS[3])
                ->references(['id', 'school_id'])->on('users')->restrictOnDelete();
            $table->foreign(['updated_by', 'school_id'], LegacySchemaIntegrityService::FOREIGN_KEYS[4])
                ->references(['id', 'school_id'])->on('users')->restrictOnDelete();
        });
        Schema::connection('school')->table('bank_transfers', function (Blueprint $table): void {
            $table->foreign(['from_account_id', 'school_id'], LegacySchemaIntegrityService::FOREIGN_KEYS[5])
                ->references(['id', 'school_id'])->on('bank_accounts')->restrictOnDelete();
            $table->foreign(['to_account_id', 'school_id'], LegacySchemaIntegrityService::FOREIGN_KEYS[6])
                ->references(['id', 'school_id'])->on('bank_accounts')->restrictOnDelete();
            $table->foreign(['created_by', 'school_id'], LegacySchemaIntegrityService::FOREIGN_KEYS[7])
                ->references(['id', 'school_id'])->on('users')->restrictOnDelete();
        });

        DB::connection('school')->statement('ALTER TABLE student_import_identities ADD CONSTRAINT '.LegacySchemaIntegrityService::CHECKS[0].' CHECK (school_id > 0)');
        DB::connection('school')->statement('ALTER TABLE bank_accounts ADD CONSTRAINT '.LegacySchemaIntegrityService::CHECKS[1].' CHECK (school_id > 0)');
        DB::connection('school')->statement('ALTER TABLE bank_transfers ADD CONSTRAINT '.LegacySchemaIntegrityService::CHECKS[2].' CHECK (school_id > 0), ADD CONSTRAINT '.LegacySchemaIntegrityService::CHECKS[3].' CHECK (from_account_id <> to_account_id), ADD CONSTRAINT '.LegacySchemaIntegrityService::CHECKS[4].' CHECK (amount > 0), ADD CONSTRAINT '.LegacySchemaIntegrityService::CHECKS[5]." CHECK (status IN ('completed', 'cancelled'))");

        if (!$integrity->round5SchemaComplete()) {
            throw new RuntimeException('Round 5 constraints were applied but failed exact schema verification.');
        }
    }

    public function down(): void
    {
        DB::connection('school')->statement('ALTER TABLE student_import_identities DROP CONSTRAINT '.LegacySchemaIntegrityService::CHECKS[0]);
        DB::connection('school')->statement('ALTER TABLE bank_accounts DROP CONSTRAINT '.LegacySchemaIntegrityService::CHECKS[1]);
        foreach (array_slice(LegacySchemaIntegrityService::CHECKS, 2) as $constraint) DB::connection('school')->statement("ALTER TABLE bank_transfers DROP CONSTRAINT {$constraint}");
        Schema::connection('school')->table('bank_transfers', function (Blueprint $table): void {
            foreach (array_slice(LegacySchemaIntegrityService::FOREIGN_KEYS, 5) as $foreign) $table->dropForeign($foreign);
        });
        Schema::connection('school')->table('bank_accounts', function (Blueprint $table): void {
            foreach (array_slice(LegacySchemaIntegrityService::FOREIGN_KEYS, 3, 2) as $foreign) $table->dropForeign($foreign);
        });
        Schema::connection('school')->table('student_import_identities', function (Blueprint $table): void {
            foreach (array_slice(LegacySchemaIntegrityService::FOREIGN_KEYS, 0, 3) as $foreign) $table->dropForeign($foreign);
        });
        Schema::connection('school')->table('bank_transfers', function (Blueprint $table): void {
            $table->dropUnique(LegacySchemaIntegrityService::INDEXES[3]);
            $table->dropColumn(LegacySchemaIntegrityService::ACTIVE_TRANSFER_REFERENCE);
        });
        Schema::connection('school')->table('bank_accounts', fn (Blueprint $table) => $table->dropUnique(LegacySchemaIntegrityService::INDEXES[2]));
        Schema::connection('school')->table('users', fn (Blueprint $table) => $table->dropUnique(LegacySchemaIntegrityService::INDEXES[1]));
        Schema::connection('school')->table('students', fn (Blueprint $table) => $table->dropUnique(LegacySchemaIntegrityService::INDEXES[0]));
    }
};
