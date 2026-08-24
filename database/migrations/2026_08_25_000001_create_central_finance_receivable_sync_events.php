<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * An append-oriented delivery/audit trail for the tenant fee-assignment
     * projection. It contains no tenant database name, student name, or
     * financial amount; those remain in their source / Central document.
     */
    public function up(): void
    {
        Schema::connection('mysql')->create('central_finance_receivable_sync_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('school_id')->index();
            $table->unsignedBigInteger('student_profile_id')->nullable()->index();
            $table->string('source_type', 80);
            $table->string('source_id', 100);
            $table->string('source_version', 64);
            $table->string('idempotency_key', 64)->unique();
            $table->string('payload_hash', 64);
            $table->string('status', 32); // processing | processed | duplicate | blocked_paid | failed
            $table->unsignedInteger('attempts')->default(1);
            $table->timestamp('processed_at')->nullable();
            // Bounded non-PII operational diagnostic, never a source payload.
            $table->string('error_code', 80)->nullable();
            $table->timestamps();

            $table->index(['school_id', 'student_profile_id', 'source_type'], 'cfrse_school_profile_source_index');
        });
    }

    public function down(): void
    {
        Schema::connection('mysql')->dropIfExists('central_finance_receivable_sync_events');
    }
};
