<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::connection('mysql')->table('central_finance_user_school_scopes', function (Blueprint $table): void {
            $table->boolean('can_approve_reimbursements')->default(false)->after('can_operate');
        });

        Schema::connection('mysql')->create('central_finance_categories', function (Blueprint $table): void {
            $table->id();
            $table->uuid('category_uuid')->unique();
            $table->unsignedBigInteger('school_id')->index();
            $table->string('type', 16); // income | expense
            $table->string('name', 120);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['school_id', 'type', 'name'], 'cfc_school_type_name_unique');
        });

        Schema::connection('mysql')->create('central_finance_expenses', function (Blueprint $table): void {
            $table->id();
            $table->uuid('expense_uuid')->unique();
            $table->unsignedBigInteger('school_id')->index();
            $table->unsignedBigInteger('category_id')->index();
            $table->unsignedBigInteger('fund_account_id')->index();
            $table->string('idempotency_key', 64)->unique();
            $table->string('reference_no', 100)->nullable();
            $table->string('payment_method', 40);
            $table->date('expense_date');
            $table->string('currency', 3);
            $table->decimal('amount', 20, 4);
            $table->text('description')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->string('edit_reason', 255)->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->string('delete_reason', 255)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['school_id', 'reference_no'], 'cfe_school_reference_unique');
        });

        Schema::connection('mysql')->create('central_finance_other_incomes', function (Blueprint $table): void {
            $table->id();
            $table->uuid('income_uuid')->unique();
            $table->unsignedBigInteger('school_id')->index();
            $table->unsignedBigInteger('category_id')->index();
            $table->unsignedBigInteger('fund_account_id')->index();
            $table->string('idempotency_key', 64)->unique();
            $table->string('reference_no', 100)->nullable();
            $table->string('payment_method', 40);
            $table->date('income_date');
            $table->string('payer', 191)->nullable();
            $table->string('currency', 3);
            $table->decimal('amount', 20, 4);
            $table->text('description')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->string('edit_reason', 255)->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->string('delete_reason', 255)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['school_id', 'reference_no'], 'cfoi_school_reference_unique');
        });

        Schema::connection('mysql')->create('central_finance_reimbursement_requests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('request_uuid')->unique();
            $table->unsignedBigInteger('school_id')->index();
            $table->unsignedBigInteger('category_id')->index();
            $table->string('idempotency_key', 64)->unique();
            $table->string('reference_no', 100)->nullable();
            $table->string('currency', 3);
            $table->decimal('amount', 20, 4);
            $table->text('description')->nullable();
            $table->string('status', 16); // pending | approved | rejected
            $table->unsignedBigInteger('requested_by');
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->string('approval_reason', 255)->nullable();
            $table->unsignedBigInteger('expense_id')->nullable()->unique();
            $table->timestamps();
            $table->unique(['school_id', 'reference_no'], 'cfrq_school_reference_unique');
        });

        Schema::connection('mysql')->create('central_finance_document_audits', function (Blueprint $table): void {
            $table->id();
            $table->uuid('audit_uuid')->unique();
            $table->unsignedBigInteger('school_id')->index();
            $table->string('document_type', 80);
            $table->unsignedBigInteger('document_id');
            $table->string('action', 40);
            $table->unsignedBigInteger('actor_id');
            $table->string('reason', 255)->nullable();
            $table->json('before_values')->nullable();
            $table->json('after_values')->nullable();
            $table->timestamps();
            $table->index(['document_type', 'document_id'], 'cfda_document_index');
        });
    }

    public function down(): void
    {
        Schema::connection('mysql')->dropIfExists('central_finance_document_audits');
        Schema::connection('mysql')->dropIfExists('central_finance_reimbursement_requests');
        Schema::connection('mysql')->dropIfExists('central_finance_other_incomes');
        Schema::connection('mysql')->dropIfExists('central_finance_expenses');
        Schema::connection('mysql')->dropIfExists('central_finance_categories');
        Schema::connection('mysql')->table('central_finance_user_school_scopes', function (Blueprint $table): void {
            $table->dropColumn('can_approve_reimbursements');
        });
    }
};
