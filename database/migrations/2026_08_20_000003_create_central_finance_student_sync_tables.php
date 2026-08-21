<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Central Finance owns only the synchronized reference projection here.
     * It intentionally does not create a payment, balance, Ledger, or any
     * replacement for a tenant's student/academic record.
     */
    public function up(): void
    {
        Schema::connection('mysql')->create('central_finance_student_profiles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('school_id')->index();
            $table->unsignedBigInteger('tenant_student_id');
            $table->uuid('source_uuid');
            $table->string('admission_no', 100)->nullable();
            $table->string('student_name', 191)->nullable();
            $table->string('enrollment_status', 32)->nullable();
            $table->timestamp('source_updated_at')->nullable();
            $table->timestamp('source_deleted_at')->nullable();
            $table->timestamp('last_synced_at');
            $table->timestamps();

            $table->unique(['school_id', 'source_uuid'], 'cfsp_school_source_uuid_unique');
            $table->unique(['school_id', 'tenant_student_id'], 'cfsp_school_tenant_student_unique');
        });

        Schema::connection('mysql')->create('central_finance_sync_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('school_id')->index();
            $table->string('source_type', 80);
            $table->uuid('source_uuid');
            $table->string('source_version', 64);
            $table->string('idempotency_key', 64)->unique();
            $table->uuid('correlation_id');
            $table->string('payload_hash', 64);
            $table->string('status', 32);
            $table->unsignedInteger('attempts')->default(1);
            $table->timestamp('processed_at')->nullable();
            // A bounded non-PII diagnostic code. Delivery systems must not
            // store source payloads or user credentials in an error field.
            $table->string('error_code', 80)->nullable();
            $table->timestamps();

            $table->index(['school_id', 'source_type', 'source_uuid'], 'cfse_school_source_index');
        });
    }

    public function down(): void
    {
        Schema::connection('mysql')->dropIfExists('central_finance_sync_events');
        Schema::connection('mysql')->dropIfExists('central_finance_student_profiles');
    }
};
