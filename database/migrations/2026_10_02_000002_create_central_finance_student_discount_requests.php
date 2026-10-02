<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Front Desk discount is an approval request, never an effective monetary
 * adjustment.  The approved Promotion remains the single downstream engine.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::connection('mysql')->create('central_finance_student_discount_requests', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('request_uuid')->unique('cf_discount_request_uuid_unique');
            $table->string('idempotency_key', 64)->unique('cf_discount_request_idempotency_unique');
            $table->unsignedBigInteger('group_id');
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('student_profile_id');
            $table->unsignedBigInteger('fees_class_type_id');
            $table->uuid('tenant_assignment_uuid');
            $table->uuid('tenant_assignment_item_uuid');
            $table->unsignedBigInteger('requested_by');
            $table->string('discount_type', 16);
            $table->decimal('discount_value', 20, 4);
            $table->decimal('gross_amount_snapshot', 20, 4);
            $table->string('currency_snapshot', 8);
            $table->string('reason', 2000);
            $table->date('effective_date');
            $table->string('status', 16)->default('pending');
            $table->unsignedBigInteger('decision_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->string('rejection_reason', 2000)->nullable();
            $table->unsignedBigInteger('promotion_id')->nullable();
            $table->timestamps();
            $table->index(['school_id', 'status', 'created_at'], 'cf_discount_request_school_status_idx');
            $table->index(['student_profile_id', 'status'], 'cf_discount_request_student_status_idx');
            $table->index(['group_id', 'status'], 'cf_discount_request_group_status_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('mysql')->dropIfExists('central_finance_student_discount_requests');
    }
};
