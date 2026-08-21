<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::connection('mysql')->create('central_finance_user_school_scopes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('school_id');
            $table->boolean('can_view')->default(true);
            $table->boolean('can_operate')->default(false);
            $table->timestamps();
            $table->unique(['user_id', 'school_id'], 'cfuss_user_school_unique');
        });
        Schema::connection('mysql')->create('central_finance_receivables', function (Blueprint $table): void {
            $table->id();
            $table->uuid('receivable_uuid')->unique();
            $table->unsignedBigInteger('school_id')->index();
            $table->unsignedBigInteger('student_profile_id')->index();
            $table->string('source_type', 80);
            $table->string('source_id', 100);
            $table->string('description', 191);
            $table->date('due_date')->nullable();
            $table->string('currency', 3);
            $table->decimal('amount_due', 20, 4);
            $table->decimal('amount_paid', 20, 4)->default(0);
            $table->string('status', 16); // open | partial | paid
            $table->timestamp('source_updated_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
            $table->unique(['school_id', 'student_profile_id', 'source_type', 'source_id'], 'cfr_source_student_unique');
            $table->index(['school_id', 'status'], 'cfr_school_status_index');
        });
        Schema::connection('mysql')->create('central_finance_payments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('payment_uuid')->unique();
            $table->unsignedBigInteger('school_id')->index();
            $table->unsignedBigInteger('receivable_id')->index();
            $table->unsignedBigInteger('fund_account_id')->index();
            $table->string('idempotency_key', 64)->unique();
            $table->string('payment_reference', 100)->nullable();
            $table->string('payment_method', 40);
            $table->string('currency', 3);
            $table->decimal('amount', 20, 4);
            $table->timestamp('paid_at');
            $table->unsignedBigInteger('received_by');
            $table->timestamps();
            $table->unique(['school_id', 'payment_reference'], 'cfp_school_reference_unique');
        });
        Schema::connection('mysql')->create('central_finance_receipts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('receipt_uuid')->unique();
            $table->unsignedBigInteger('school_id')->index();
            $table->unsignedBigInteger('payment_id')->unique();
            $table->string('receipt_no', 100)->unique();
            $table->timestamp('issued_at');
            $table->unsignedBigInteger('issued_by');
            $table->timestamps();
        });
    }
    public function down(): void
    {
        Schema::connection('mysql')->dropIfExists('central_finance_receipts');
        Schema::connection('mysql')->dropIfExists('central_finance_payments');
        Schema::connection('mysql')->dropIfExists('central_finance_receivables');
        Schema::connection('mysql')->dropIfExists('central_finance_user_school_scopes');
    }
};
