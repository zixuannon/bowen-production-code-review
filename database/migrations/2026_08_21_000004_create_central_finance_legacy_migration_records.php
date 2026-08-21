<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::connection('mysql')->create('central_finance_legacy_migration_records', function (Blueprint $table): void {
            $table->id();
            $table->string('migration_key', 191)->unique();
            $table->unsignedBigInteger('school_id')->index();
            $table->string('source_type', 64);
            $table->string('source_id', 100);
            $table->string('source_hash', 64);
            $table->string('status', 32); // imported|soft_deleted|cancelled|pending|unresolved
            $table->string('central_type', 64)->nullable();
            $table->unsignedBigInteger('central_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('migrated_at')->nullable();
            $table->timestamps();
            $table->unique(['school_id', 'source_type', 'source_id'], 'cflmr_school_source_unique');
            $table->index(['school_id', 'status'], 'cflmr_school_status_index');
        });
    }

    public function down(): void
    {
        Schema::connection('mysql')->dropIfExists('central_finance_legacy_migration_records');
    }
};
