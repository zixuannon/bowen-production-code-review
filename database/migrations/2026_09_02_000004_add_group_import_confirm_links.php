<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::connection('mysql')->table('central_finance_group_import_batches', function (Blueprint $table): void {
            $table->unsignedBigInteger('confirmed_by')->nullable()->index()->after('uploaded_by');
            $table->timestamp('confirmed_at')->nullable()->after('school_summary');
            $table->text('failure_reason')->nullable()->after('confirmed_at');
            $table->foreign('confirmed_by', 'cfgib_confirmer_fk')->references('id')->on('users')->restrictOnDelete();
        });
        Schema::connection('mysql')->table('central_finance_group_import_preview_rows', function (Blueprint $table): void {
            $table->string('canonical_source_type', 32)->nullable()->after('idempotency_key');
            $table->unsignedBigInteger('canonical_source_id')->nullable()->after('canonical_source_type');
            $table->uuid('canonical_source_uuid')->nullable()->after('canonical_source_id');
            $table->timestamp('confirmed_at')->nullable()->after('canonical_source_uuid');
            $table->index(['canonical_source_type', 'canonical_source_id'], 'cfgipr_source_index');
        });
    }

    public function down(): void
    {
        $sqlite = Schema::connection('mysql')->getConnection()->getDriverName() === 'sqlite';
        Schema::connection('mysql')->table('central_finance_group_import_preview_rows', function (Blueprint $table): void {
            $table->dropIndex('cfgipr_source_index');
            $table->dropColumn(['canonical_source_type', 'canonical_source_id', 'canonical_source_uuid', 'confirmed_at']);
        });
        if ($sqlite) {
            \Illuminate\Support\Facades\DB::connection('mysql')->statement('DROP INDEX IF EXISTS central_finance_group_import_batches_confirmed_by_index');
        }
        Schema::connection('mysql')->table('central_finance_group_import_batches', function (Blueprint $table) use ($sqlite): void {
            // SQLite recreates the table for a dropped column and cannot
            // compile DROP FOREIGN KEY. The test-only SQLite path still
            // removes the additive columns; MySQL removes the named FK first.
            if (!$sqlite) {
                $table->dropForeign('cfgib_confirmer_fk');
            }
            $table->dropColumn(['confirmed_by', 'confirmed_at', 'failure_reason']);
        });
    }
};
