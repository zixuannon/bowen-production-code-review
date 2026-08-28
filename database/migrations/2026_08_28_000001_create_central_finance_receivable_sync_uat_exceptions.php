<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('central_finance_receivable_sync_uat_exceptions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('student_profile_id');
            $table->uuid('student_source_uuid');
            $table->string('context', 80);
            $table->string('status', 20)->default('active');
            $table->string('reason', 500);
            $table->unsignedBigInteger('authorized_by');
            $table->timestamp('enabled_at');
            $table->timestamp('disabled_at')->nullable();
            $table->unsignedBigInteger('disabled_by')->nullable();
            $table->string('disabled_reason', 500)->nullable();
            $table->timestamps();

            $table->unique(['school_id', 'student_profile_id', 'context'], 'central_finance_uat_cutoff_exception_unique');
            $table->index(['school_id', 'student_source_uuid', 'status'], 'central_finance_uat_cutoff_exception_scope');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('central_finance_receivable_sync_uat_exceptions');
    }
};
